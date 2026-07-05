<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    currentTerm: { type: Object, default: null },
    upcomingMeetings: { type: Array, default: () => [] },
    recentTasks: { type: Array, default: () => [] },
    membersByRole: { type: Array, default: () => [] },
});

const { t, locale } = useI18n();

const completionRate = computed(() => {
    const total = (props.stats.tasks_open || 0) + (props.stats.tasks_completed || 0);
    return total ? Math.round((props.stats.tasks_completed / total) * 100) : 0;
});

function fmtDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString(locale.value === 'ar' ? 'ar' : locale.value, { day: '2-digit', month: 'short', year: 'numeric' });
}
function roleLabel(r) { return t(r); }
function initial(n) { return (n || '?').charAt(0).toUpperCase(); }
</script>

<template>
    <Head :title="t('board_of_directors')" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('board_of_directors') }}</h1>
        </template>

        <div class="space-y-6">
            <!-- Current term banner -->
            <div v-if="currentTerm" class="card flex flex-wrap items-center gap-x-6 gap-y-2 bg-gradient-to-r from-primary-600 to-primary-700 p-5 text-white">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-white/70">{{ t('current_term') }}</p>
                    <p class="text-2xl font-extrabold">{{ currentTerm.name }}</p>
                </div>
                <div v-if="currentTerm.start_date || currentTerm.end_date" class="text-sm text-white/90">
                    <p class="text-xs font-semibold uppercase tracking-wide text-white/70">{{ t('term_start') }} → {{ t('term_end') }}</p>
                    <p class="font-bold">{{ fmtDate(currentTerm.start_date) || '…' }} → {{ fmtDate(currentTerm.end_date) || '…' }}</p>
                </div>
                <div class="flex gap-6">
                    <div class="text-center"><p class="text-2xl font-extrabold">{{ stats.members || 0 }}</p><p class="text-xs text-white/70">{{ t('board_members') }}</p></div>
                    <div class="text-center"><p class="text-2xl font-extrabold">{{ stats.meetings_total || 0 }}</p><p class="text-xs text-white/70">{{ t('meetings') }}</p></div>
                    <div class="text-center"><p class="text-2xl font-extrabold">{{ stats.tasks_total || 0 }}</p><p class="text-xs text-white/70">{{ t('tasks') }}</p></div>
                </div>
            </div>

            <!-- Quick links -->
            <div class="flex flex-wrap gap-2">
                <Link :href="route('board.members')" class="inline-flex items-center gap-1.5 rounded-xl bg-white px-3.5 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800"><Icon name="board" /> {{ t('board_members') }}</Link>
                <Link :href="route('board.meetings')" class="inline-flex items-center gap-1.5 rounded-xl bg-white px-3.5 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800"><Icon name="calendar" /> {{ t('meetings') }}</Link>
                <Link :href="route('board.tasks')" class="inline-flex items-center gap-1.5 rounded-xl bg-white px-3.5 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800"><Icon name="task" /> {{ t('tasks') }}</Link>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard :label="t('board_members')" :value="stats.members || 0" icon="board" color="primary" />
                <StatCard :label="t('meetings')" :value="stats.meetings_held || 0" icon="calendar" color="slate" />
                <StatCard :label="t('tasks')" :value="stats.tasks_open || 0" icon="task" color="amber" :suffix="t('in_progress')" />
                <StatCard :label="t('completion_rate')" :value="completionRate" icon="check" color="emerald" suffix="%">
                    <span v-if="stats.tasks_overdue" class="mt-1 inline-block text-xs font-semibold text-rose-600 dark:text-rose-400">{{ stats.tasks_overdue }} {{ t('overdue') }}</span>
                </StatCard>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <!-- Members by role -->
                <div class="card p-5 lg:col-span-2">
                    <div class="mb-4 flex items-center justify-between">
                        <p class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('board_members') }}</p>
                        <Link :href="route('board.members')" class="text-xs font-semibold text-primary-600 hover:text-primary-700">{{ t('manage') ?? t('edit') }}</Link>
                    </div>
                    <div v-if="membersByRole.length" class="grid gap-3 sm:grid-cols-2">
                        <div v-for="m in membersByRole" :key="m.id" class="flex items-center gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-to-br from-primary-500 to-primary-600 text-base font-bold text-white">
                                <img v-if="m.photo_url" :src="m.photo_url" :alt="m.name" class="h-full w-full object-cover" />
                                <span v-else>{{ initial(m.name) }}</span>
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-slate-900 dark:text-slate-100">{{ m.name }}</p>
                                <p class="truncate text-xs font-semibold text-primary-600 dark:text-primary-400">{{ roleLabel(m.role) }}</p>
                            </div>
                        </div>
                    </div>
                    <p v-else class="py-8 text-center text-sm text-slate-400">{{ t('no_data') }}</p>
                </div>

                <!-- Upcoming meetings -->
                <div class="card p-5">
                    <p class="mb-4 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('upcoming_meetings') }}</p>
                    <ul v-if="upcomingMeetings.length" class="space-y-3">
                        <li v-for="mt in upcomingMeetings" :key="mt.id">
                            <Link :href="route('board.meetings.show', mt.id)" class="block rounded-xl bg-slate-50 p-3 transition-colors hover:bg-slate-100 dark:bg-slate-800/50 dark:hover:bg-slate-800">
                                <p class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ mt.title }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ fmtDate(mt.meeting_date) }} · {{ t(mt.type) }}</p>
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="py-6 text-center text-sm text-slate-400">{{ t('no_meetings') }}</p>
                </div>
            </div>

            <!-- Recent open tasks -->
            <div class="card p-5">
                <div class="mb-4 flex items-center justify-between">
                    <p class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('recent_tasks') }}</p>
                    <Link :href="route('board.tasks')" class="text-xs font-semibold text-primary-600 hover:text-primary-700">{{ t('tasks') }}</Link>
                </div>
                <ul v-if="recentTasks.length" class="space-y-3">
                    <li v-for="tk in recentTasks" :key="tk.id" class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50">
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <p class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ tk.title }}</p>
                            <span class="shrink-0 text-xs text-slate-500 dark:text-slate-400">{{ tk.member?.name || t('unassigned') }}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                <div class="h-full rounded-full bg-primary-500" :style="{ width: (tk.progress || 0) + '%' }"></div>
                            </div>
                            <span class="text-xs font-bold text-slate-500 dark:text-slate-400">{{ tk.progress || 0 }}%</span>
                        </div>
                    </li>
                </ul>
                <p v-else class="py-6 text-center text-sm text-slate-400">{{ t('no_tasks') }}</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
