<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';
import { KIND_DOT, addMonths, dateKey, parseDay } from '@/lib/attendanceCalendar';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    categoryId: { type: Number, default: null },
    month: { type: String, required: true }, // YYYY-MM
    sessions: { type: Array, default: () => [] },
    preseason: { type: Object, default: null },
    hasSchedule: { type: Boolean, default: false },
});
const emit = defineEmits(['navigate']);
const { t, locale } = useI18n();
const { statuses, color, summaryText } = useAttendanceCodes();

const todayKey = dateKey(new Date());
const anchor = computed(() => parseDay(`${props.month}-01`));
const monthLabel = computed(() => anchor.value.toLocaleDateString(locale.value, { month: 'long', year: 'numeric' }));
const weekdays = computed(() => Array.from({ length: 7 }, (_, i) =>
    new Date(Date.UTC(2024, 0, 1 + i)).toLocaleDateString(locale.value, { weekday: 'short', timeZone: 'UTC' })));

const byDate = computed(() => props.sessions.reduce((acc, s) => ((acc[s.date] ??= []).push(s), acc), {}));

// 6-week grid starting on the Monday on/before the 1st.
const cells = computed(() => {
    const first = anchor.value;
    const start = new Date(first);
    start.setDate(first.getDate() - ((first.getDay() + 6) % 7));
    return Array.from({ length: 42 }, (_, i) => {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        const k = dateKey(d);
        return { key: k, day: d.getDate(), inMonth: d.getMonth() === first.getMonth(), sessions: byDate.value[k] ?? [] };
    });
});

const go = (params) => emit('navigate', { category_id: props.categoryId, month: props.month, ...params });

const chip = {
    planned: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    held: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
    cancelled: 'bg-slate-200 text-slate-500 line-through dark:bg-slate-700 dark:text-slate-400',
};
const chipTitle = (s) => [t(`att.kind.${s.kind}`), t(`att.state.${s.state}`), s.categories.map((c) => c.name).join(', '), s.title, summaryText(s.summary)]
    .filter(Boolean).join(' · ');

const preseasonLabel = computed(() => {
    if (!props.preseason) return '';
    const { season, done, target } = props.preseason;
    return target === null ? t('att.preseason_no_target', { season, done }) : t('att.preseason_progress', { season, done, target });
});
const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <select :value="categoryId" :class="input" :aria-label="t('att.category')" @change="go({ category_id: Number($event.target.value) })">
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rtl:rotate-180" :aria-label="t('att.prev_month')" @click="go({ month: addMonths(month, -1) })"><Icon name="back" /></button>
            <span class="min-w-[9rem] text-center text-sm font-bold capitalize text-slate-900 dark:text-slate-100">{{ monthLabel }}</span>
            <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 ltr:rotate-180" :aria-label="t('att.next_month')" @click="go({ month: addMonths(month, 1) })"><Icon name="back" /></button>
            <span v-if="preseason" class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">{{ preseasonLabel }}</span>
        </div>

        <p v-if="!hasSchedule" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ t('att.no_schedule') }}</p>

        <div class="grid grid-cols-7 gap-px overflow-hidden rounded-xl bg-slate-200 ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-800">
            <div v-for="w in weekdays" :key="w" class="bg-slate-50 p-2 text-center text-xs font-semibold text-slate-500 dark:bg-slate-900">{{ w }}</div>
            <div v-for="cell in cells" :key="cell.key" class="min-h-[5.5rem] min-w-0 bg-white p-1.5 dark:bg-slate-900" :class="{ 'opacity-40': !cell.inMonth }">
                <div class="mb-1 text-xs font-semibold" :class="cell.key === todayKey ? 'text-primary-600' : 'text-slate-400'">{{ cell.day }}</div>
                <Link v-for="s in cell.sessions" :key="s.id" :href="route('attendance.sessions.show', s.id)" class="mb-1 block rounded px-1.5 py-0.5 text-[11px] font-medium" :class="chip[s.state]" :title="chipTitle(s)">
                    <span class="flex items-center gap-1">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="KIND_DOT[s.kind]"></span>
                        <span dir="ltr">{{ s.start_time }}</span>
                        <span v-if="s.categories.length > 1" class="shrink-0 rounded bg-amber-200/70 px-1 text-[10px] text-amber-900 dark:bg-amber-500/30 dark:text-amber-100">+{{ s.categories.length - 1 }}</span>
                        <span v-if="s.title" class="min-w-0 truncate">{{ s.title }}</span>
                        <span v-if="s.state === 'held'" class="ms-auto">✓</span>
                    </span>
                    <span v-if="s.summary" class="mt-0.5 flex h-1 overflow-hidden rounded-full">
                        <span v-for="st in statuses" v-show="s.summary[st]" :key="st" :style="{ backgroundColor: color(st), flexGrow: s.summary[st] ?? 0 }"></span>
                    </span>
                </Link>
            </div>
        </div>

        <div class="flex flex-wrap gap-3 text-xs text-slate-500">
            <span v-for="(cls, kind) in KIND_DOT" :key="kind" class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" :class="cls"></span>{{ t(`att.kind.${kind}`) }}</span>
        </div>
    </div>
</template>
