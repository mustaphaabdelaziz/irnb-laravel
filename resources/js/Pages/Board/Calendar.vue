<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    month: { type: String, required: true }, // YYYY-MM
    events: { type: Array, default: () => [] },
});
const { t, locale } = useI18n();

const anchor = computed(() => new Date(`${props.month}-01T00:00:00`));
const todayKey = new Date().toISOString().slice(0, 10);

const monthLabel = computed(() =>
    anchor.value.toLocaleDateString(locale.value === 'ar' ? 'ar' : locale.value, { month: 'long', year: 'numeric' })
);

// Weekday headers, Monday-first.
const weekdays = computed(() => {
    const base = new Date(Date.UTC(2024, 0, 1)); // a Monday
    return Array.from({ length: 7 }, (_, i) => {
        const d = new Date(base);
        d.setUTCDate(base.getUTCDate() + i);
        return d.toLocaleDateString(locale.value === 'ar' ? 'ar' : locale.value, { weekday: 'short' });
    });
});

function key(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// 6-week grid starting on the Monday on/before the 1st.
const cells = computed(() => {
    const first = new Date(anchor.value);
    const offset = (first.getDay() + 6) % 7; // days since Monday
    const start = new Date(first);
    start.setDate(first.getDate() - offset);
    return Array.from({ length: 42 }, (_, i) => {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        return { date: d, key: key(d), inMonth: d.getMonth() === anchor.value.getMonth() };
    });
});

const eventsByDate = computed(() => {
    const map = {};
    for (const e of props.events) (map[e.date] ??= []).push(e);
    return map;
});

function shift(delta) {
    const d = new Date(anchor.value);
    d.setMonth(d.getMonth() + delta);
    const m = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    router.get(route('board.calendar', { month: m }), {}, { preserveState: true, preserveScroll: true, only: ['month', 'events'] });
}
function goToday() {
    const d = new Date();
    router.get(route('board.calendar', { month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` }), {}, { preserveState: true, preserveScroll: true, only: ['month', 'events'] });
}

const meetingChip = {
    scheduled: 'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300',
    held: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
    cancelled: 'bg-slate-200 text-slate-500 line-through dark:bg-slate-700 dark:text-slate-400',
};

// ---- Create meeting from a clicked date ----
const showForm = ref(false);
const form = useForm({ title: '', type: 'ordinary', meeting_date: '', location: '', status: 'scheduled', quorum_required: null, agenda: [''] });

function openCreate(cell) {
    form.reset();
    form.meeting_date = `${cell.key}T18:00`;
    showForm.value = true;
}
function submit() {
    form.transform((d) => ({ ...d, agenda: d.agenda.filter((x) => x && x.trim()) }))
        .post(route('board.meetings.store'), { onSuccess: () => { form.reset(); showForm.value = false; } });
}
</script>

<template>
    <Head :title="t('calendar')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('board.index')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"><Icon name="back" /></Link>
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('calendar') }}</h1>
            </div>
        </template>

        <div class="space-y-4">
            <!-- Toolbar -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <button @click="shift(-1)" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 rtl:rotate-180"><Icon name="back" /></button>
                    <span class="min-w-[9rem] text-center text-sm font-bold capitalize text-slate-900 dark:text-slate-100">{{ monthLabel }}</span>
                    <button @click="shift(1)" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 ltr:rotate-180"><Icon name="back" /></button>
                    <button @click="goToday" class="ms-1 rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-500 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800">{{ t('today') }}</button>
                </div>
                <div class="flex items-center gap-3 text-xs text-slate-400">
                    <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-primary-500"></span>{{ t('meetings') }}</span>
                    <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-amber-500"></span>{{ t('tasks') }}</span>
                    <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-rose-500"></span>{{ t('subscriptions') }}</span>
                </div>
            </div>

            <!-- Grid -->
            <div class="card overflow-hidden p-0">
                <div class="grid grid-cols-7 border-b border-slate-100 bg-slate-50 dark:border-slate-800 dark:bg-slate-800/40">
                    <div v-for="w in weekdays" :key="w" class="py-2 text-center text-xs font-bold uppercase text-slate-400">{{ w }}</div>
                </div>
                <div class="grid grid-cols-7">
                    <div
                        v-for="cell in cells" :key="cell.key"
                        class="group relative min-h-[6.5rem] border-b border-e border-slate-100 p-1.5 dark:border-slate-800"
                        :class="[cell.inMonth ? 'bg-white dark:bg-slate-900' : 'bg-slate-50/60 dark:bg-slate-900/40', cell.key === todayKey ? 'ring-1 ring-inset ring-primary-400' : '']"
                    >
                        <div class="mb-1 flex items-center justify-between">
                            <span class="text-xs font-semibold" :class="cell.inMonth ? 'text-slate-500 dark:text-slate-300' : 'text-slate-300 dark:text-slate-600'">{{ cell.date.getDate() }}</span>
                            <button @click="openCreate(cell)" :title="t('add_meeting')" class="rounded p-0.5 text-slate-300 opacity-0 transition-opacity hover:bg-primary-50 hover:text-primary-600 group-hover:opacity-100 dark:hover:bg-primary-500/10"><Icon name="plus" class="h-3.5 w-3.5" /></button>
                        </div>
                        <div class="space-y-1">
                            <template v-for="e in (eventsByDate[cell.key] || [])" :key="e.kind + e.id">
                                <Link v-if="e.kind === 'meeting'" :href="route('board.meetings.show', e.id)"
                                    class="block truncate rounded px-1.5 py-0.5 text-[0.7rem] font-semibold" :class="meetingChip[e.status]">
                                    {{ e.time }} {{ e.title }}
                                </Link>
                                <div v-else-if="e.kind === 'task'" class="flex items-center gap-1 truncate text-[0.7rem] text-amber-600 dark:text-amber-400" :title="e.title + (e.assignee ? ' — ' + e.assignee : '')">
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span><span class="truncate">{{ e.title }}</span>
                                </div>
                                <div v-else class="flex items-center gap-1 truncate text-[0.7rem] text-rose-600 dark:text-rose-400" :title="t('subscription_due') + ' — ' + e.title">
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-rose-500"></span><span class="truncate">{{ e.title }}</span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create meeting modal (teleported so the layout header/sidebar can't overlap it) -->
        <Teleport to="body">
        <div v-if="showForm" class="fixed inset-0 z-[100] flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm sm:items-center" @click.self="showForm = false">
            <form @submit.prevent="submit" class="card my-auto w-full max-w-lg space-y-4 p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">{{ t('add_meeting') }}</h2>
                    <button type="button" @click="showForm = false" class="text-slate-400 hover:text-slate-600"><Icon name="xcircle" /></button>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm sm:col-span-2"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('title') }}</span>
                        <input v-model="form.title" required class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('meeting_type') }}</span>
                        <select v-model="form.type" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="ordinary">{{ t('ordinary') }}</option><option value="extraordinary">{{ t('extraordinary') }}</option><option value="general_assembly">{{ t('general_assembly') }}</option>
                        </select></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('date') }}</span>
                        <input v-model="form.meeting_date" type="datetime-local" required class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('location') }}</span>
                        <input v-model="form.location" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('quorum') }}</span>
                        <input v-model="form.quorum_required" type="number" min="0" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="showForm = false" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                    <button :disabled="form.processing" class="rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('create') }}</button>
                </div>
            </form>
        </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
