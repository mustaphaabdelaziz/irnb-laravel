<script setup>
import { computed } from 'vue';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';
import { pct } from '@/lib/attendanceStats';

/**
 * One proportional bar split by status, with a legend of counts and shares,
 * in the configured names and colours. Used by the statistics page and the
 * dashboard card.
 */
const props = defineProps({
    counts: { type: Object, default: () => ({}) }, // { status: number }
    // { status: % of expected } from AttendanceStats, as on the statistics page: a not_counted
    // status has none (null). Without it, each status's share of all marks is shown.
    pct: { type: Object, default: null },
});
const { statuses, activeStatuses, label, color } = useAttendanceCodes();

// Every status that can be picked, plus a hidden custom code that still has marks here.
const shown = computed(() => statuses.value.filter((s) => activeStatuses.value.includes(s) || props.counts[s]));
const total = computed(() => shown.value.reduce((sum, s) => sum + (props.counts[s] ?? 0), 0));
const share = (s) => (total.value ? ((props.counts[s] ?? 0) / total.value) * 100 : 0);
const shownPct = (s) => (props.pct ? pct(props.pct[s]) : `${share(s).toFixed(1)}%`);
const summary = computed(() => shown.value.map((s) => `${label(s)}: ${props.counts[s] ?? 0}`).join(', '));
</script>

<template>
    <div class="space-y-2">
        <div class="flex h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="img" :aria-label="summary">
            <span
                v-for="s in shown"
                v-show="counts[s]"
                :key="s"
                class="h-full"
                :style="{ width: `${share(s)}%`, backgroundColor: color(s) }"
                :title="`${label(s)}: ${counts[s] ?? 0}`"
            ></span>
        </div>
        <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
            <li v-for="s in shown" :key="s" class="inline-flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: color(s) }"></span>
                <span class="text-slate-600 dark:text-slate-300">{{ label(s) }}</span>
                <span class="font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ counts[s] ?? 0 }}</span>
                <bdi dir="ltr" class="tabular-nums text-slate-400">{{ shownPct(s) }}</bdi>
            </li>
        </ul>
    </div>
</template>
