<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
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
    playerStatuses: { type: Array, default: null }, // add-session dialog: roster status checkboxes (optional prop, loaded on first open)
    rosterStatusIds: { type: Array, default: null },
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
const createDate = ref(today);
// From the buttons (today) or from a click on a calendar day (that date).
function openCreate(kind, date = today) {
    createKind.value = kind;
    createDate.value = date;
    // The roster status checkboxes are an optional prop: fetched once, before the dialog opens.
    if (props.playerStatuses === null) {
        router.reload({ only: ['playerStatuses', 'rosterStatusIds'], onSuccess: () => (showCreate.value = true) });
        return;
    }
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
                    <IconButton :href="route('attendance.stats')" icon="dashboard" :label="t('att.statistics')" />
                    <IconButton v-if="categoryId" :href="route('attendance.grid', { category_id: categoryId, month })" icon="menu" :label="t('att.grid')" />
                    <IconButton v-if="categoryId && view === 'month'" :href="sheetHref" external target="_blank" :title="t('att.sheet.print_hint')" icon="print" :label="t('att.sheet.print')" />
                    <IconButton v-if="can('attendance', 'edit')" :href="route('attendance.settings')" icon="settings" :label="t('att.settings')" />
                </div>
            </div>
        </template>

        <p v-if="!categories.length" class="text-sm text-slate-500">{{ t('att.no_category') }}</p>

        <div v-else class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
                <ViewSwitcher :view="view" @switch="switchView" />
                <div v-if="can('attendance', 'add')" class="flex gap-2">
                    <IconButton icon="plus" :label="t('att.add_extra')" variant="primary" @click="openCreate('extra')" />
                    <IconButton icon="flag" :label="t('att.add_preseason')" @click="openCreate('preseason')" />
                </div>
            </div>

            <MonthView v-if="view === 'month'" :categories="categories" :category-id="categoryId" :month="month" :sessions="sessions" :preseason="preseason" :has-schedule="hasSchedule" :can-add="can('attendance', 'add')" @navigate="navigate" @add="(date) => openCreate('extra', date)" />
            <WeekView v-else-if="view === 'week'" :week="week" :sessions="sessions" :can-add="can('attendance', 'add')" @navigate="navigate" @add="(date) => openCreate('extra', date)" />
            <AgendaView v-else-if="view === 'agenda'" :categories="categories" :category-id="categoryId" :month="month" :sessions="sessions" @navigate="navigate" />
            <TimelineView v-else-if="view === 'timeline'" :categories="categories" :category-id="categoryId" :kind="kind" :from="from" :to="to" :events="events" @navigate="navigate" />
        </div>

        <AddSessionModal :show="showCreate" :kind="createKind" :categories="categories" :category-id="categoryId" :date="createDate" :player-statuses="playerStatuses ?? []" :roster-status-ids="rosterStatusIds ?? []" @close="showCreate = false" />
    </AuthenticatedLayout>
</template>
