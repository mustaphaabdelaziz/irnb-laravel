<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import '@/lib/registerCharts';
import { Doughnut } from 'vue-chartjs';

const { t } = useI18n();

const props = defineProps({
    title: { type: String, required: true },
    // [{ key, label, count }] — key is what the filter uses, label what's shown.
    stats: { type: Array, default: () => [] },
    // Currently selected key ('' = no filter). v-model.
    modelValue: { type: [String, Number], default: '' },
    palette: {
        type: Array,
        default: () => ['#02a85c', '#0284c7', '#d97706', '#e11d48', '#7c3aed', '#0891b2', '#65a30d', '#db2777'],
    },
});
const emit = defineEmits(['update:modelValue']);

const total = computed(() => props.stats.reduce((s, c) => s + c.count, 0));
const pct = (count) => (total.value ? Math.round((count / total.value) * 100) : 0);
const isActive = (stat) => String(props.modelValue) === String(stat.key);

function toggle(stat) {
    emit('update:modelValue', isActive(stat) ? '' : stat.key);
}

const chartData = computed(() => ({
    labels: props.stats.map((s) => s.label),
    datasets: [{
        data: props.stats.map((s) => s.count),
        backgroundColor: props.stats.map((_, i) => props.palette[i % props.palette.length]),
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
            <p class="text-xs font-semibold text-slate-400">{{ total }} {{ t('players') }}</p>
        </div>
        <div class="flex flex-col items-center gap-4 sm:flex-row">
            <div class="relative h-44 w-44 shrink-0">
                <Doughnut :data="chartData" :options="chartOptions" />
                <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                    <span class="text-2xl font-extrabold text-slate-900 dark:text-slate-100">{{ total }}</span>
                    <span class="text-[0.65rem] font-semibold uppercase text-slate-400">{{ t('players') }}</span>
                </div>
            </div>
            <div class="flex flex-1 flex-wrap content-center gap-2">
                <button v-for="(stat, i) in stats" :key="String(stat.key)"
                    @click="toggle(stat)"
                    class="flex items-center gap-2 rounded-xl px-3 py-1.5 text-sm ring-1 transition-colors"
                    :class="isActive(stat)
                        ? 'bg-primary-600 text-white ring-primary-600'
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
