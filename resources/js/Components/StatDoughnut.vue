<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import '@/lib/registerCharts';
import { Doughnut } from 'vue-chartjs';
import { Card } from '@/Components/ui/card';
import { useStatSelection } from '@/Composables/useStatSelection';

const { t } = useI18n();

const props = defineProps({
    title: { type: String, required: true },
    // [{ key, label, count, static?, color? }] — key is what the filter uses,
    // label what's shown. A static slice (e.g. "others") is shown but can't
    // filter. `color` pins a slice's colour; without it the palette is dealt
    // out by position, so a slice changes colour when the order changes.
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

const { total, pct, clickable, isActive, toggle, hasSelection, selectedTotal, shownTotal, filtered, clear } = useStatSelection(props, emit);

const colorOf = (stat, i) => stat.color || props.palette[i % props.palette.length];

// The canvas says nothing to a screen reader: this is its text.
const summary = computed(() => `${props.title}: ${props.stats.map((s) => `${s.label} ${s.count}`).join(', ')}`);

const chartData = computed(() => ({
    labels: props.stats.map((s) => s.label),
    datasets: [{
        data: props.stats.map((s) => s.count),
        // With a selection, unselected slices fade so the chosen ones stand out.
        backgroundColor: props.stats.map((s, i) => {
            const color = colorOf(s, i);
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
    <Card class="border-border/70 p-4 shadow-none">
        <div class="mb-3 flex items-baseline justify-between gap-3">
            <h3 class="min-w-0 truncate text-sm font-semibold text-slate-700 dark:text-slate-200">{{ title }}</h3>
            <div class="flex shrink-0 items-baseline gap-2 text-xs">
                <button v-if="filtered" type="button" @click="clear"
                    class="rounded font-semibold text-primary-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-300">
                    {{ t('filter.clear') }}
                </button>
                <span class="font-medium tabular-nums text-slate-500 dark:text-slate-400">
                    <template v-if="hasSelection">{{ selectedTotal }} / </template>{{ total }} {{ unit ?? t('players') }}
                </span>
            </div>
        </div>

        <p v-if="!stats.length" class="py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</p>

        <div v-else class="flex flex-col items-center gap-4 sm:flex-row">
            <div class="relative h-44 w-44 shrink-0" role="img" :aria-label="summary">
                <!-- update-mode none: drawn animated once, then redrawn in place — a
                     filter reload must not replay the animation on every chart. -->
                <Doughnut :data="chartData" :options="chartOptions" update-mode="none" />
                <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                    <span class="text-2xl font-extrabold tabular-nums text-slate-900 dark:text-slate-100">{{ shownTotal }}</span>
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ unit ?? t('players') }}</span>
                </div>
            </div>
            <div class="flex flex-1 flex-wrap content-center gap-2">
                <!-- A slice that can't filter is a plain label, not a dead button. -->
                <component
                    :is="clickable(stat) ? 'button' : 'div'"
                    v-for="(stat, i) in stats" :key="String(stat.key)"
                    :type="clickable(stat) ? 'button' : undefined"
                    @click="toggle(stat)"
                    :aria-pressed="clickable(stat) ? isActive(stat) : undefined"
                    class="flex items-center gap-2 rounded-xl px-3 py-1.5 text-sm transition-colors"
                    :class="isActive(stat)
                        ? 'bg-slate-900 text-white ring-1 ring-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-1 dark:bg-slate-100 dark:text-slate-900 dark:ring-slate-100'
                        : !clickable(stat)
                            ? 'text-slate-500 dark:text-slate-400'
                            : 'bg-slate-50 text-slate-700 ring-1 ring-slate-200 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-700'">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: colorOf(stat, i) }"></span>
                    <span class="font-semibold">{{ stat.label }}</span>
                    <span class="text-xs font-semibold tabular-nums"
                        :class="isActive(stat) ? 'text-white/80 dark:text-slate-600' : 'text-slate-500 dark:text-slate-400'">
                        {{ stat.count }} · {{ pct(stat.count) }}%
                    </span>
                </component>
            </div>
        </div>
    </Card>
</template>
