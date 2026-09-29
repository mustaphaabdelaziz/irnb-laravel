<script setup>
import { computed } from 'vue';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';

/**
 * One proportional bar split by status, with a legend of counts and shares,
 * in the configured names and colours. Used by the statistics page and the
 * dashboard card.
 */
const props = defineProps({
    counts: { type: Object, default: () => ({}) }, // { status: number }
});
const { statuses, label, color } = useAttendanceCodes();

const total = computed(() => statuses.reduce((sum, s) => sum + (props.counts[s] ?? 0), 0));
const share = (s) => (total.value ? ((props.counts[s] ?? 0) / total.value) * 100 : 0);
const summary = computed(() => statuses.map((s) => `${label(s)}: ${props.counts[s] ?? 0}`).join(', '));
</script>

<template>
    <div class="space-y-2">
        <div class="flex h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="img" :aria-label="summary">
            <span
                v-for="s in statuses"
                v-show="counts[s]"
                :key="s"
                class="h-full"
                :style="{ width: `${share(s)}%`, backgroundColor: color(s) }"
                :title="`${label(s)}: ${counts[s] ?? 0}`"
            ></span>
        </div>
        <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
            <li v-for="s in statuses" :key="s" class="inline-flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: color(s) }"></span>
                <span class="text-slate-600 dark:text-slate-300">{{ label(s) }}</span>
                <span class="font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ counts[s] ?? 0 }}</span>
                <bdi dir="ltr" class="tabular-nums text-slate-400">{{ share(s).toFixed(1) }}%</bdi>
            </li>
        </ul>
    </div>
</template>
