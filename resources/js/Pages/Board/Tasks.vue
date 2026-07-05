<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import StatDoughnut from '@/Components/StatDoughnut.vue';
import '@/lib/registerCharts';
import { Bar } from 'vue-chartjs';

const props = defineProps({
    columns: { type: Object, default: () => ({}) },
    members: { type: Array, default: () => [] },
    meetings: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
});
const { t, locale } = useI18n();

const order = ['not_started', 'in_progress', 'completed', 'cancelled'];

// board = kanban columns, timeline = gantt.
const view = ref('board');

// Client-side filters over the kanban columns.
const search = ref('');
const fMember = ref(null);
const fPriority = ref('');
function matches(tk) {
    const q = search.value.trim().toLowerCase();
    return (!q || (tk.title || '').toLowerCase().includes(q))
        && (!fMember.value || tk.board_member_id === fMember.value)
        && (!fPriority.value || tk.priority === fPriority.value);
}
const filteredColumns = computed(() => {
    const out = {};
    for (const col of order) {
        out[col] = (props.columns[col] || []).filter(matches);
    }
    return out;
});

// ---- Gantt timeline ----
const allFiltered = computed(() => order.flatMap((c) => props.columns[c] || []).filter(matches));
const dated = computed(() => allFiltered.value
    .filter((t) => t.due_date)
    .map((t) => {
        const due = new Date(`${t.due_date.slice(0, 10)}T00:00:00`);
        let start = t.created_at ? new Date(t.created_at) : due;
        if (start > due) start = due;
        return { ...t, _start: start, _due: due };
    }));
const undatedCount = computed(() => allFiltered.value.length - dated.value.length);
const barColor = {
    not_started: 'bg-slate-400',
    in_progress: 'bg-primary-500',
    completed: 'bg-emerald-500',
    cancelled: 'bg-slate-300 dark:bg-slate-600',
};
// Vertical timeline: tasks sorted by due date, grouped by month.
const dotColor = {
    not_started: 'bg-slate-400 ring-slate-100 dark:ring-slate-800',
    in_progress: 'bg-primary-500 ring-primary-100 dark:ring-primary-500/20',
    completed: 'bg-emerald-500 ring-emerald-100 dark:ring-emerald-500/20',
    cancelled: 'bg-slate-300 ring-slate-100 dark:bg-slate-600 dark:ring-slate-800',
};
const timelineGroups = computed(() => {
    const items = [...dated.value].sort((a, b) => a._due - b._due);
    const groups = [];
    let current = null;
    for (const it of items) {
        const label = it._due.toLocaleDateString(locale.value === 'ar' ? 'ar' : locale.value, { month: 'long', year: 'numeric' });
        if (!current || current.label !== label) {
            current = { label, items: [] };
            groups.push(current);
        }
        current.items.push(it);
    }
    return groups;
});
const colStyle = {
    not_started: 'text-slate-500',
    in_progress: 'text-primary-600',
    completed: 'text-emerald-600',
    cancelled: 'text-slate-400',
};
const priorityChip = {
    low: 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
    medium: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
    high: 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300',
};

// ---- Stats view ----
const statusChips = computed(() => order
    .map((s) => ({ key: s, label: t(s), count: props.stats[{ not_started: 'notStarted', in_progress: 'inProgress', completed: 'completed', cancelled: 'cancelled' }[s]] || 0 }))
    .filter((r) => r.count > 0));
const priorityChips = computed(() => (props.stats.byPriority || []).map((p) => ({ key: p.priority, label: t(p.priority), count: p.count })));
const statusPalette = ['#94a3b8', '#0284c7', '#02a85c', '#cbd5e1'];
const priorityPalette = ['#e11d48', '#d97706', '#94a3b8'];

const monthLabels = computed(() => Array.from({ length: 12 }, (_, i) =>
    new Date(2020, i, 1).toLocaleDateString(locale.value === 'ar' ? 'ar' : locale.value, { month: 'short' })));
const monthlyChart = computed(() => ({
    labels: monthLabels.value,
    datasets: [{ label: t('completed'), data: props.stats.monthly || [], backgroundColor: '#02a85c', borderRadius: 4 }],
}));
const yearlyChart = computed(() => ({
    labels: (props.stats.yearly || []).map((y) => y.year),
    datasets: [{ label: t('completed'), data: (props.stats.yearly || []).map((y) => y.count), backgroundColor: '#0284c7', borderRadius: 4 }],
}));
const barOptions = {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
};

const showModal = ref(false);
const editingId = ref(null);
const form = useForm({ title: '', description: '', board_member_id: null, board_meeting_id: null, due_date: '', status: 'not_started', progress: 0, priority: 'medium' });

function openCreate() {
    editingId.value = null;
    form.reset();
    showModal.value = true;
}
function openEdit(tk) {
    editingId.value = tk.id;
    form.title = tk.title; form.description = tk.description || ''; form.board_member_id = tk.board_member_id;
    form.board_meeting_id = tk.board_meeting_id; form.due_date = tk.due_date || ''; form.status = tk.status;
    form.progress = tk.progress || 0; form.priority = tk.priority;
    showModal.value = true;
}
function submit() {
    const opts = { preserveScroll: true, onSuccess: () => { showModal.value = false; } };
    if (editingId.value) form.put(route('board.tasks.update', editingId.value), opts);
    else form.post(route('board.tasks.store'), opts);
}
function remove() {
    if (editingId.value && window.confirm(t('confirm_delete'))) {
        router.delete(route('board.tasks.destroy', editingId.value), { preserveScroll: true, onSuccess: () => { showModal.value = false; } });
    }
}
function fmtDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString(locale.value === 'ar' ? 'ar' : locale.value, { day: '2-digit', month: 'short' });
}
function isOverdue(tk) {
    return tk.due_date && ['not_started', 'in_progress'].includes(tk.status) && new Date(tk.due_date) < new Date(new Date().toDateString());
}

// ---- Drag & drop between status columns ----
const draggingId = ref(null);
const dragOverCol = ref(null);

function onDragStart(tk, e) {
    draggingId.value = tk.id;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', String(tk.id)); // Firefox needs a payload
}
function onDragEnd() {
    draggingId.value = null;
    dragOverCol.value = null;
}
function onDrop(col) {
    const id = draggingId.value;
    dragOverCol.value = null;
    draggingId.value = null;
    if (!id) return;

    const tk = order.flatMap((c) => props.columns[c] || []).find((t) => t.id === id);
    if (!tk || tk.status === col) return;

    // Completing via drag fills progress to 100; reopening a completed card resets it.
    const progress = col === 'completed' ? 100 : (tk.status === 'completed' ? 0 : tk.progress);
    router.put(route('board.tasks.update', id), {
        title: tk.title,
        description: tk.description,
        board_member_id: tk.board_member_id,
        board_meeting_id: tk.board_meeting_id,
        due_date: tk.due_date,
        priority: tk.priority,
        status: col,
        progress,
    }, { preserveScroll: true, preserveState: false });
}
</script>

<template>
    <Head :title="t('tasks')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('board.index')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"><Icon name="back" /></Link>
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('action_items') }}</h1>
            </div>
        </template>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-slate-400"><Icon name="search" /></span>
                <input v-model="search" :placeholder="t('search')" class="w-48 rounded-xl border-slate-200 bg-white ps-9 text-sm dark:border-slate-700 dark:bg-slate-800" />
            </div>
            <select v-model="fMember" class="rounded-xl border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                <option :value="null">{{ t('assignee') }}</option>
                <option v-for="m in members" :key="m.id" :value="m.id">{{ m.name }}</option>
            </select>
            <select v-model="fPriority" class="rounded-xl border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                <option value="">{{ t('priority') }}</option>
                <option value="high">{{ t('high') }}</option><option value="medium">{{ t('medium') }}</option><option value="low">{{ t('low') }}</option>
            </select>
            <div class="ms-auto inline-flex rounded-xl bg-slate-100 p-0.5 dark:bg-slate-800">
                <button @click="view = 'board'" class="rounded-lg px-3 py-1.5 text-xs font-bold transition-colors" :class="view === 'board' ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'">{{ t('board_view') }}</button>
                <button @click="view = 'timeline'" class="rounded-lg px-3 py-1.5 text-xs font-bold transition-colors" :class="view === 'timeline' ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'">{{ t('timeline') }}</button>
                <button @click="view = 'stats'" class="rounded-lg px-3 py-1.5 text-xs font-bold transition-colors" :class="view === 'stats' ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'">{{ t('statistics') }}</button>
            </div>
            <a :href="route('board.tasks.export')" class="inline-flex items-center gap-1.5 rounded-xl bg-white px-3.5 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800"><Icon name="download" /> {{ t('export') }}</a>
            <button @click="openCreate" class="inline-flex items-center gap-1.5 rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700"><Icon name="plus" /> {{ t('add_task') }}</button>
        </div>

        <div v-if="view === 'board'" class="grid gap-4 lg:grid-cols-4">
            <div v-for="col in order" :key="col"
                class="rounded-2xl border-2 border-transparent bg-slate-100/60 p-3 transition-colors dark:bg-slate-900/40"
                :class="dragOverCol === col ? '!border-dashed !border-primary-400 bg-primary-50/60 dark:bg-primary-500/5' : ''"
                @dragover.prevent="dragOverCol = col"
                @dragleave.self="dragOverCol = null"
                @drop="onDrop(col)">
                <p class="mb-3 flex items-center justify-between px-1 text-xs font-bold uppercase tracking-wide" :class="colStyle[col]">
                    {{ t(col) }}
                    <span class="rounded-full bg-white px-2 py-0.5 text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ (filteredColumns[col] || []).length }}</span>
                </p>
                <div class="min-h-[3rem] space-y-2.5">
                    <div v-for="tk in filteredColumns[col]" :key="tk.id"
                        draggable="true"
                        @dragstart="onDragStart(tk, $event)"
                        @dragend="onDragEnd"
                        @click="openEdit(tk)"
                        class="block w-full cursor-grab rounded-xl bg-white p-3 text-start shadow-sm ring-1 ring-slate-200/70 transition-all hover:shadow-md active:cursor-grabbing dark:bg-slate-800 dark:ring-slate-700"
                        :class="draggingId === tk.id ? 'opacity-40' : ''">
                        <div class="mb-1.5 flex items-start justify-between gap-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ tk.title }}</p>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[0.65rem] font-bold" :class="priorityChip[tk.priority]">{{ t(tk.priority) }}</span>
                        </div>
                        <div v-if="col !== 'completed' && col !== 'cancelled'" class="mb-2 flex items-center gap-2">
                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-full rounded-full bg-primary-500" :style="{ width: (tk.progress || 0) + '%' }"></div></div>
                            <span class="text-[0.65rem] font-bold text-slate-400">{{ tk.progress || 0 }}%</span>
                        </div>
                        <div class="flex items-center justify-between text-xs text-slate-400">
                            <span class="truncate">{{ tk.member?.name || t('unassigned') }}</span>
                            <span v-if="tk.due_date" :class="isOverdue(tk) ? 'font-bold text-rose-500' : ''">{{ fmtDate(tk.due_date) }}</span>
                        </div>
                    </div>
                    <p v-if="!(filteredColumns[col] || []).length" class="py-4 text-center text-xs text-slate-300 dark:text-slate-600">{{ dragOverCol === col ? t('drop_here') : '—' }}</p>
                </div>
            </div>
        </div>

        <!-- Statistics / evaluation -->
        <div v-if="view === 'stats'" class="space-y-4">
            <!-- KPI cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="card p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('total') }} {{ t('tasks') }}</p>
                    <p class="mt-1 text-3xl font-extrabold text-slate-900 dark:text-slate-100">{{ stats.total }}</p>
                </div>
                <div class="card p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('completion_rate') }}</p>
                    <div class="mt-1 flex items-end gap-2">
                        <p class="text-3xl font-extrabold text-emerald-600 dark:text-emerald-400">{{ stats.completionRate }}%</p>
                        <p class="pb-1 text-xs text-slate-400">{{ stats.completed }}/{{ stats.total }}</p>
                    </div>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-emerald-500" :style="{ width: stats.completionRate + '%' }"></div></div>
                </div>
                <div class="card p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('in_progress') }}</p>
                    <p class="mt-1 text-3xl font-extrabold text-primary-600 dark:text-primary-400">{{ stats.inProgress }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ t('avg') }} {{ stats.avgProgress }}%</p>
                </div>
                <div class="card p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('overdue') }}</p>
                    <p class="mt-1 text-3xl font-extrabold" :class="stats.overdue > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-slate-100'">{{ stats.overdue }}</p>
                </div>
            </div>

            <!-- Distributions -->
            <div class="grid gap-4 lg:grid-cols-2">
                <StatDoughnut v-if="statusChips.length" :title="t('by_status')" :stats="statusChips" :palette="statusPalette" />
                <StatDoughnut v-if="priorityChips.length" :title="t('by_priority')" :stats="priorityChips" :palette="priorityPalette" />
            </div>

            <!-- Top members evaluation -->
            <div class="card p-5">
                <div class="mb-3 flex items-center justify-between">
                    <p class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('top_members') }}</p>
                    <span class="text-xs text-slate-400">{{ t('by_completed_tasks') }}</span>
                </div>
                <div v-if="stats.topMembers?.length" class="space-y-3">
                    <div v-for="(m, i) in stats.topMembers" :key="m.name" class="flex items-center gap-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-extrabold"
                            :class="['bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300', 'bg-slate-100 text-slate-500 dark:bg-slate-800', 'bg-orange-100 text-orange-700 dark:bg-orange-500/20'][i]">{{ i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <p class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ m.name }}</p>
                                <p class="shrink-0 text-xs text-slate-400"><span class="font-bold text-emerald-600 dark:text-emerald-400">{{ m.completed }}</span> / {{ m.assigned }} · {{ m.rate }}%</p>
                            </div>
                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-emerald-500" :style="{ width: m.rate + '%' }"></div></div>
                        </div>
                    </div>
                </div>
                <p v-else class="py-6 text-center text-sm text-slate-400">{{ t('no_data') }}</p>
            </div>

            <!-- Monthly + yearly evaluation -->
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="card p-5">
                    <p class="mb-3 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('completed_monthly') }} · {{ stats.currentYear }}</p>
                    <div class="h-52"><Bar :data="monthlyChart" :options="barOptions" /></div>
                </div>
                <div class="card p-5">
                    <p class="mb-3 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('completed_yearly') }}</p>
                    <div v-if="stats.yearly?.length" class="h-52"><Bar :data="yearlyChart" :options="barOptions" /></div>
                    <p v-else class="py-10 text-center text-sm text-slate-400">{{ t('no_data') }}</p>
                </div>
            </div>
        </div>

        <!-- Vertical timeline: tasks by due date, grouped by month -->
        <div v-if="view === 'timeline'" class="card p-5">
            <div v-if="timelineGroups.length" class="mx-auto max-w-3xl">
                <template v-for="group in timelineGroups" :key="group.label">
                    <!-- Month heading -->
                    <div class="mb-2 flex items-center gap-3">
                        <span class="text-sm font-bold capitalize text-slate-900 dark:text-slate-100">{{ group.label }}</span>
                        <span class="h-px flex-1 bg-slate-100 dark:bg-slate-800"></span>
                        <span class="text-xs font-semibold text-slate-400">{{ group.items.length }}</span>
                    </div>
                    <!-- Spine -->
                    <ol class="relative mb-4 ms-2 border-s-2 border-slate-100 dark:border-slate-800">
                        <li v-for="tk in group.items" :key="tk.id" class="relative ms-6 pb-4 last:pb-0">
                            <!-- Node -->
                            <span class="absolute -start-[1.95rem] top-1.5 h-3.5 w-3.5 rounded-full ring-4" :class="dotColor[tk.status]"></span>
                            <button @click="openEdit(tk)"
                                class="block w-full rounded-xl bg-slate-50 p-3 text-start ring-1 ring-slate-100 transition-shadow hover:shadow-md dark:bg-slate-800/50 dark:ring-slate-700/50">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ tk.title }}</p>
                                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[0.65rem] font-bold" :class="priorityChip[tk.priority]">{{ t(tk.priority) }}</span>
                                </div>
                                <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                                    <span class="inline-flex items-center gap-1 font-semibold" :class="colStyle[tk.status]">{{ t(tk.status) }}</span>
                                    <span>{{ tk.member?.name || t('unassigned') }}</span>
                                    <span class="ms-auto inline-flex items-center gap-1" :class="isOverdue(tk) ? 'font-bold text-rose-500' : ''">
                                        <Icon name="calendar" class="h-3.5 w-3.5" /> {{ fmtDate(tk.due_date) }}
                                    </span>
                                </div>
                                <div v-if="tk.status !== 'cancelled'" class="mt-2 flex items-center gap-2">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-full rounded-full" :class="barColor[tk.status]" :style="{ width: (tk.progress || 0) + '%' }"></div></div>
                                    <span class="text-[0.65rem] font-bold text-slate-400">{{ tk.progress || 0 }}%</span>
                                </div>
                            </button>
                        </li>
                    </ol>
                </template>
            </div>
            <p v-else class="py-10 text-center text-sm text-slate-400">{{ t('no_dated_tasks') }}</p>
            <p v-if="undatedCount" class="mt-1 border-t border-slate-100 pt-3 text-center text-xs text-slate-400 dark:border-slate-800">{{ undatedCount }} {{ t('tasks_without_due_date') }}</p>
        </div>

        <!-- Modal (teleported to body so the layout header/sidebar can't overlap it) -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-[100] flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm sm:items-center" @click.self="showModal = false">
                <div class="my-auto w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 dark:bg-slate-900 dark:ring-white/10">
                    <!-- Header -->
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-slate-800">
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-300"><Icon name="task" /></span>
                            <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">{{ editingId ? t('edit') : t('add_task') }}</h2>
                        </div>
                        <button type="button" @click="showModal = false" class="rounded-lg p-1 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"><Icon name="xcircle" /></button>
                    </div>

                    <!-- Body -->
                    <form id="task-form" @submit.prevent="submit" class="max-h-[70vh] space-y-4 overflow-y-auto px-5 py-4">
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-slate-600 dark:text-slate-300">{{ t('task_title') }}</span>
                            <input v-model="form.title" :placeholder="t('task_title')" required
                                class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800" />
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-slate-600 dark:text-slate-300">{{ t('description') }}</span>
                            <textarea v-model="form.description" :placeholder="t('description')" rows="2"
                                class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800"></textarea>
                        </label>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium text-slate-600 dark:text-slate-300">{{ t('assignee') }}</span>
                                <select v-model="form.board_member_id" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800">
                                    <option :value="null">{{ t('unassigned') }}</option>
                                    <option v-for="m in members" :key="m.id" :value="m.id">{{ m.name }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium text-slate-600 dark:text-slate-300">{{ t('due_date') }}</span>
                                <input v-model="form.due_date" type="date" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800" />
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium text-slate-600 dark:text-slate-300">{{ t('status') }}</span>
                                <select v-model="form.status" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800">
                                    <option v-for="s in order" :key="s" :value="s">{{ t(s) }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium text-slate-600 dark:text-slate-300">{{ t('priority') }}</span>
                                <select v-model="form.priority" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800">
                                    <option value="low">{{ t('low') }}</option><option value="medium">{{ t('medium') }}</option><option value="high">{{ t('high') }}</option>
                                </select>
                            </label>
                        </div>
                        <div>
                            <div class="mb-1 flex justify-between text-xs font-medium text-slate-500"><span>{{ t('progress') }}</span><span class="font-bold text-primary-600 dark:text-primary-400">{{ form.progress }}%</span></div>
                            <input v-model.number="form.progress" type="range" min="0" max="100" step="5" class="w-full accent-primary-600" />
                        </div>
                    </form>

                    <!-- Footer -->
                    <div class="flex items-center justify-between border-t border-slate-100 px-5 py-4 dark:border-slate-800">
                        <button v-if="editingId" type="button" @click="remove" class="inline-flex items-center gap-1.5 text-sm font-semibold text-rose-500 hover:text-rose-600"><Icon name="xcircle" /> {{ t('delete') }}</button>
                        <span v-else></span>
                        <div class="flex gap-2">
                            <button type="button" @click="showModal = false" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                            <button type="submit" form="task-form" :disabled="form.processing" class="rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('save') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
