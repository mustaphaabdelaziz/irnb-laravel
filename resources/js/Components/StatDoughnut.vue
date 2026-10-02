<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import '@/lib/registerCharts';
import { Doughnut } from 'vue-chartjs';

const { t } = useI18n();

const props = defineProps({
    title: { type: String, required: true },
    // [{ key, label, count, static? }] — key is what the filter uses, label
    // what's shown. A static slice (e.g. "others") is shown but can't filter.
    stats: { type: Array, default: () => [] },
    // Currently selected key ('' = no filter), or a list of selected keys for
    // a multi-select filter: a click then toggles that key in the list. v-model.
    modelValue: { type: [String, Number, Array], default: '' },
    palette: {
        type: Array,
        default: () => ['#02a85c', '#0284c7', '#d97706', '#e11d48', '#7c3aed', '#0891b2', '#65a30d', '#db2777'],
    },
    // What the total counts ("sessions" on the attendance card); players by default.
    unit: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);

const total = computed(() => props.stats.reduce((s, c) => s + c.count, 0));
const pct = (count) => (total.value ? Math.round((count / total.value) * 100) : 0);
const multiple = computed(() => Array.isArray(props.modelValue));
// In a list an empty key (e.g. "uncategorized") has no filter value to add.
const clickable = (stat) => !stat.static && !(multiple.value && (stat.key === '' || stat.key === null || stat.key === undefined));
const isActive = (stat) => clickable(stat) && (multiple.value
    ? props.modelValue.map(String).includes(String(stat.key))
    : String(props.modelValue) === String(stat.key));

function toggle(stat) {
    if (!clickable(stat)) return;
    if (!multiple.value) {
        emit('update:modelValue', isActive(stat) ? '' : stat.key);
        return;
    }
    const key = String(stat.key);
    const list = props.modelValue.map(String);
    emit('update:modelValue', list.includes(key) ? list.filter((k) => k !== key) : [...list, key]);
}

const hasSelection = computed(() => props.stats.some((s) => isActive(s)));

const chartData = computed(() => ({
    labels: props.stats.map((s) => s.label),
    datasets: [{
        data: props.stats.map((s) => s.count),
        // With a selection, unselected slices fade so the chosen ones stand out.
        backgroundColor: props.stats.map((s, i) => {
            const color = props.palette[i % props.palette.length];
            return hasSelection.value && !isActive(s) && /^#[0-9a-f]{6}$/i.test(color) ? `${color}55` : color;
        }),
        borderWidth: 0,
    }],
}));

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    cutout: '62%',
    plugins: {
        legend: { display: false }, // the chips beside the chart are the legend (clickable filter)
        tooltip: {
            callbacks: {
                label: (ctx) => ` ${ctx.label}: ${ctx.parsed} (${total.value ? Math.round((ctx.parsed / total.value) * 100) : 0}%)`,
            },
        },
    },
    onClick: (_, elements) => {
        if (elements.length) toggle(props.stats[elements[0].index]);
    },
}));
</script>

<template>
    <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <div class="mb-2 flex items-center justify-between">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ title }}</p>
            <p class="text-xs font-semibold text-slate-400">{{ total }} {{ unit ?? t('players') }}</p>
        </div>
        <div class="flex flex-col items-center gap-4 sm:flex-row">
            <div class="relative h-44 w-44 shrink-0">
                <Doughnut :data="chartData" :options="chartOptions" />
                <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                    <span class="text-2xl font-extrabold text-slate-900 dark:text-slate-100">{{ total }}</span>
                    <span class="text-[0.65rem] font-semibold uppercase text-slate-400">{{ unit ?? t('players') }}</span>
                </div>
            </div>
            <div class="flex flex-1 flex-wrap content-center gap-2">
                <button v-for="(stat, i) in stats" :key="String(stat.key)"
                    @click="toggle(stat)"
                    :disabled="!clickable(stat)"
                    :aria-pressed="clickable(stat) ? isActive(stat) : undefined"
                    class="flex items-center gap-2 rounded-xl px-3 py-1.5 text-sm ring-1 transition-colors"
                    :class="isActive(stat)
                        ? 'bg-primary-600 text-white ring-primary-600'
                        : !clickable(stat)
                            ? 'cursor-default bg-slate-50 text-slate-500 ring-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700'
                            : 'bg-slate-50 text-slate-700 ring-slate-200 hover:bg-primary-50 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700'">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: palette[i % palette.length] }"></span>
                    <span class="font-semibold">{{ stat.label }}</span>
                    <span class="rounded-full bg-white/80 px-1.5 py-0.5 text-xs font-bold text-slate-600 dark:bg-slate-900/60 dark:text-slate-300"
                        :class="isActive(stat) ? '!bg-white/25 !text-white' : ''">
                        {{ stat.count }} · {{ pct(stat.count) }}%
                    </span>
                </button>
            </div>
        </div>
    </div>
</template>
