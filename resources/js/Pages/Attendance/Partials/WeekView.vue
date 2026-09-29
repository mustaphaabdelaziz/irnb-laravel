<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import { KIND_BLOCK, addDays, dateKey, dayLabel as formatDayLabel, toMinutes } from '@/lib/attendanceCalendar';

const props = defineProps({
    week: { type: Object, required: true }, // { start, end } 'Y-m-d', Monday to Sunday
    sessions: { type: Array, default: () => [] },
});
const emit = defineEmits(['navigate']);
const { t, locale } = useI18n();

const HOUR_PX = 48;
const today = dateKey(new Date());
const days = computed(() => Array.from({ length: 7 }, (_, i) => addDays(props.week.start, i)));
const dayLabel = (key) => formatDayLabel(key, locale.value);
const rangeLabel = computed(() => `${dayLabel(props.week.start)} – ${dayLabel(props.week.end)}`);

// Hour rows from the earliest start to the latest end of the week, never less than 08:00–20:00.
const firstHour = computed(() => Math.min(8, ...props.sessions.map((s) => Math.floor(toMinutes(s.start_time) / 60))));
const lastHour = computed(() => Math.max(20, ...props.sessions.map((s) => Math.ceil(toMinutes(s.end_time) / 60))));
const hours = computed(() => Array.from({ length: lastHour.value - firstHour.value }, (_, i) => firstHour.value + i));
const height = computed(() => hours.value.length * HOUR_PX);

/**
 * Overlapping sessions sit side by side: sessions are grouped into clusters
 * that overlap in time, and each takes the first free lane of its cluster.
 */
function layoutDay(list) {
    const sorted = [...list].sort((a, b) => a.start_time.localeCompare(b.start_time) || a.end_time.localeCompare(b.end_time));
    const placed = [];
    let cluster = [];
    let clusterEnd = '';
    const flush = () => {
        const laneEnds = [];
        const members = cluster.map((session) => {
            let lane = laneEnds.findIndex((end) => end <= session.start_time);
            if (lane === -1) {
                lane = laneEnds.length;
                laneEnds.push(session.end_time);
            } else {
                laneEnds[lane] = session.end_time;
            }
            return { session, lane };
        });
        members.forEach((m) => placed.push({ ...m, lanes: laneEnds.length }));
        cluster = [];
        clusterEnd = '';
    };
    for (const s of sorted) {
        if (cluster.length && s.start_time >= clusterEnd) flush();
        cluster.push(s);
        if (s.end_time > clusterEnd) clusterEnd = s.end_time;
    }
    if (cluster.length) flush();
    return placed;
}
const blocks = computed(() => Object.fromEntries(days.value.map((d) => [d, layoutDay(props.sessions.filter((s) => s.date === d))])));

function blockStyle({ session, lane, lanes }) {
    const top = ((toMinutes(session.start_time) - firstHour.value * 60) / 60) * HOUR_PX;
    const tall = Math.max(22, ((toMinutes(session.end_time) - toMinutes(session.start_time)) / 60) * HOUR_PX);
    return { top: `${top}px`, height: `${tall}px`, insetInlineStart: `${(lane * 100) / lanes}%`, width: `calc(${100 / lanes}% - 2px)` };
}
const blockTitle = (s) => [`${s.start_time}–${s.end_time}`, t(`att.kind.${s.kind}`), t(`att.state.${s.state}`), s.categories.map((c) => c.name).join(', '), s.title]
    .filter(Boolean).join(' · ');
const go = (date) => emit('navigate', { date });
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rtl:rotate-180" :aria-label="t('att.prev_week')" @click="go(addDays(week.start, -7))"><Icon name="back" /></button>
            <span class="min-w-[12rem] text-center text-sm font-bold capitalize text-slate-900 dark:text-slate-100">{{ rangeLabel }}</span>
            <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 ltr:rotate-180" :aria-label="t('att.next_week')" @click="go(addDays(week.start, 7))"><Icon name="back" /></button>
            <button class="rounded-lg px-3 py-1 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800" @click="go(today)">{{ t('att.today') }}</button>
        </div>

        <p v-if="!sessions.length" class="text-sm text-slate-500">{{ t('att.no_sessions_week') }}</p>

        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="grid min-w-[48rem]" style="grid-template-columns: 3.5rem repeat(7, minmax(0, 1fr))">
                <div class="border-b border-slate-100 dark:border-slate-800"></div>
                <div v-for="d in days" :key="d" class="border-b border-s border-slate-100 p-2 text-center text-xs font-semibold capitalize dark:border-slate-800" :class="d === today ? 'text-primary-600' : 'text-slate-500'">{{ dayLabel(d) }}</div>

                <div class="relative" :style="{ height: `${height}px` }">
                    <div v-for="(h, i) in hours" :key="h" class="absolute inset-x-0 pe-1 text-end text-[10px] text-slate-400" :style="{ top: `${i * HOUR_PX}px` }"><span dir="ltr">{{ String(h).padStart(2, '0') }}:00</span></div>
                </div>
                <div v-for="d in days" :key="`col-${d}`" class="relative border-s border-slate-100 dark:border-slate-800" :class="{ 'bg-primary-50/40 dark:bg-primary-500/5': d === today }" :style="{ height: `${height}px` }">
                    <div v-for="(h, i) in hours" :key="h" class="absolute inset-x-0 border-t border-slate-100 dark:border-slate-800" :style="{ top: `${i * HOUR_PX}px` }"></div>
                    <Link
                        v-for="b in blocks[d]"
                        :key="b.session.id"
                        :href="route('attendance.sessions.show', b.session.id)"
                        class="absolute overflow-hidden rounded-md border-s-4 p-1 text-[11px] leading-tight shadow-sm hover:z-10 hover:shadow"
                        :class="[KIND_BLOCK[b.session.kind], b.session.state === 'cancelled' ? 'line-through opacity-60' : '']"
                        :style="blockStyle(b)"
                        :title="blockTitle(b.session)"
                    >
                        <div class="font-semibold"><span dir="ltr">{{ b.session.start_time }}–{{ b.session.end_time }}</span><span v-if="b.session.state === 'held'" class="ms-1">✓</span></div>
                        <div class="truncate">{{ b.session.categories.map((c) => c.name).join(' · ') }}</div>
                        <div v-if="b.session.title" class="truncate opacity-80">{{ b.session.title }}</div>
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
