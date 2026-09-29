<script setup>
import { computed, nextTick, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';
import { KIND_BORDER, KIND_DOT, addMonths, dateKey, dayLabel as formatDayLabel, monthKey, monthsBetween, parseDay } from '@/lib/attendanceCalendar';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    categoryId: { type: Number, default: null }, // null = every category
    kind: { type: String, default: null }, // null = every kind
    from: { type: String, required: true }, // YYYY-MM
    to: { type: String, required: true },
    events: { type: Array, default: () => [] },
});
const emit = defineEmits(['navigate']);
const { t, locale } = useI18n();
const { statuses, label, color } = useAttendanceCodes();

const MAX_MONTHS = 12; // AttendanceCalendarController::TIMELINE_MAX_MONTHS
const KINDS = ['regular', 'preseason', 'extra'];
const today = dateKey(new Date());
const thisMonth = monthKey(new Date());

const months = computed(() => Array.from({ length: monthsBetween(props.from, props.to) + 1 }, (_, i) => addMonths(props.from, i)));
const byMonth = computed(() => props.events.reduce((acc, e) => ((acc[e.date.slice(0, 7)] ??= []).push(e), acc), {}));
const monthLabel = (m) => parseDay(`${m}-01`).toLocaleDateString(locale.value, { month: 'long', year: 'numeric' });
const dayLabel = (key) => formatDayLabel(key, locale.value);
// The "today" line sits just before the first event on or after today.
const todayEventKey = computed(() => (props.from <= thisMonth && thisMonth <= props.to ? props.events.find((e) => e.date >= today)?.key ?? null : null));

// Filters stay; the window grows by one month, then slides once it holds MAX_MONTHS.
const filters = computed(() => ({ category_id: props.categoryId, kind: props.kind }));
const span = computed(() => monthsBetween(props.from, props.to) + 1);
const earlier = () => emit('navigate', { ...filters.value, from: addMonths(props.from, -1), to: span.value >= MAX_MONTHS ? addMonths(props.to, -1) : props.to });
const later = () => emit('navigate', { ...filters.value, from: span.value >= MAX_MONTHS ? addMonths(props.from, 1) : props.from, to: addMonths(props.to, 1) });
const goToday = () => emit('navigate', { ...filters.value }, { preserveScroll: false });
const filter = (patch) => emit('navigate', { ...filters.value, from: props.from, to: props.to, ...patch });

const summaryParts = (e) => statuses.filter((s) => e.summary?.[s]).map((s) => ({ status: s, text: `${e.summary[s]} ${label(s)}` }));
function dotClass(e) {
    if (e.type === 'closure') return 'bg-slate-400';
    if (e.type === 'milestone') return 'bg-amber-500';
    return e.state === 'cancelled' ? 'border-2 border-slate-400 bg-white dark:bg-slate-900' : KIND_DOT[e.kind];
}
// A cancelled session is drawn hollow (dashed outline, no fill).
const cardClass = (e) => (e.state === 'cancelled'
    ? 'border-2 border-dashed border-slate-300 bg-transparent text-slate-500 dark:border-slate-700'
    : ['border-s-4 bg-white ring-1 ring-slate-200 hover:ring-primary-300 dark:bg-slate-900 dark:ring-slate-800', KIND_BORDER[e.kind]]);
const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';

onMounted(() => {
    // Opened on the current month (first visit or "today"): bring today into view.
    if (props.from === props.to && props.to === thisMonth) {
        nextTick(() => document.getElementById('att-today')?.scrollIntoView({ block: 'center' }));
    }
});
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <select :value="categoryId ?? ''" :class="input" :aria-label="t('att.category')" @change="filter({ category_id: $event.target.value ? Number($event.target.value) : null })">
                <option value="">{{ t('att.all_categories') }}</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <select :value="kind ?? ''" :class="input" :aria-label="t('att.col.kind')" @change="filter({ kind: $event.target.value || null })">
                <option value="">{{ t('att.all_kinds') }}</option>
                <option v-for="k in KINDS" :key="k" :value="k">{{ t(`att.kind.${k}`) }}</option>
            </select>
            <button class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800" @click="goToday">{{ t('att.today') }}</button>
        </div>

        <button class="w-full rounded-lg border border-dashed border-slate-300 py-2 text-sm text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="earlier">↑ {{ t('att.earlier') }}</button>

        <ol class="space-y-6">
            <li v-for="m in months" :key="m">
                <h3 class="mb-3 text-sm font-bold capitalize text-slate-900 dark:text-slate-100">{{ monthLabel(m) }}</h3>
                <ol class="space-y-3 border-s-2 border-slate-200 ps-6 dark:border-slate-800">
                    <li v-if="!byMonth[m]" class="text-sm text-slate-400">{{ t('att.no_events') }}</li>
                    <li v-for="e in byMonth[m] ?? []" :key="e.key">
                        <p v-if="e.key === todayEventKey" id="att-today" class="mb-3 flex items-center gap-2 text-xs font-semibold text-primary-600">
                            <span class="h-px flex-1 bg-primary-300"></span>{{ t('att.today') }}<span class="h-px flex-1 bg-primary-300"></span>
                        </p>
                        <div class="relative">
                            <span class="absolute -start-[1.94rem] top-4 h-3 w-3 rounded-full ring-4 ring-white dark:ring-slate-900" :class="dotClass(e)"></span>

                            <Link v-if="e.type === 'session'" :href="route('attendance.sessions.show', e.id)" class="block rounded-xl p-3" :class="cardClass(e)">
                                <div class="flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                                    <span class="font-semibold capitalize">{{ dayLabel(e.date) }}</span>
                                    <span dir="ltr">{{ e.start_time }}–{{ e.end_time }}</span>
                                    <span>{{ t(`att.kind.${e.kind}`) }}</span>
                                    <span class="ms-auto">{{ t(`att.state.${e.state}`) }}</span>
                                </div>
                                <div class="mt-1 font-semibold text-slate-900 dark:text-slate-100" :class="{ 'line-through': e.state === 'cancelled' }">{{ e.categories.map((c) => c.name).join(' · ') }}</div>
                                <div v-if="e.title" class="text-sm text-slate-600 dark:text-slate-300">{{ e.title }}</div>
                                <div v-if="e.state === 'held' && e.summary" class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600 dark:text-slate-300">
                                    <span v-for="p in summaryParts(e)" :key="p.status" class="inline-flex items-center gap-1">
                                        <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: color(p.status) }"></span>{{ p.text }}
                                    </span>
                                </div>
                                <div v-if="e.state === 'cancelled'" class="mt-1 text-xs">{{ t('att.cancelled_because', { reason: e.cancel_reason }) }}</div>
                            </Link>

                            <div v-else-if="e.type === 'closure'" class="rounded-xl bg-slate-100 p-3 text-sm dark:bg-slate-800">
                                <div class="text-xs font-semibold text-slate-500">{{ t('att.closure') }} · <span dir="ltr">{{ e.start_date }} → {{ e.end_date }}</span></div>
                                <div class="font-medium text-slate-800 dark:text-slate-200">{{ e.reason }}</div>
                            </div>

                            <Link v-else :href="route('attendance.sessions.show', e.session_id)" class="flex items-center gap-2 rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-800 hover:bg-amber-100 dark:bg-amber-500/10 dark:text-amber-300">
                                <Icon name="flag" />
                                <span>{{ e.milestone === 'started' ? t('att.milestone.started', { category: e.category }) : t('att.milestone.completed', { category: e.category, done: e.done, target: e.target }) }}</span>
                                <span class="ms-auto text-xs font-normal capitalize">{{ dayLabel(e.date) }}</span>
                            </Link>
                        </div>
                    </li>
                </ol>
            </li>
        </ol>

        <button class="w-full rounded-lg border border-dashed border-slate-300 py-2 text-sm text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="later">↓ {{ t('att.later') }}</button>
    </div>
</template>
