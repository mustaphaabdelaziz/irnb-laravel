<script setup>
import { computed } from 'vue';

/**
 * A twelve-point trend line, drawn as inline SVG.
 *
 * Deliberately not a Chart.js instance: six of these sit in the hero row, and
 * six canvases with their own animation loops cost far more than six paths for
 * a graphic with no axis, no legend and no tooltip.
 */
const props = defineProps({
    points: { type: Array, default: () => [] },
    tone: { type: String, default: 'neutral' },
});

const WIDTH = 96;
const HEIGHT = 28;

const toneStroke = {
    positive: 'text-emerald-600 dark:text-emerald-400',
    negative: 'text-rose-600 dark:text-rose-400',
    neutral: 'text-muted-foreground/60',
};

const usable = computed(() => props.points.filter((p) => typeof p === 'number' && Number.isFinite(p)));

// Two points is the minimum that can describe a direction. One point is not a
// trend, so the component renders nothing rather than a misleading flat line.
const hasShape = computed(() => usable.value.length >= 2);

const path = computed(() => {
    const values = usable.value;
    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;
    const step = WIDTH / (values.length - 1);

    return values
        .map((value, index) => {
            const x = index * step;
            const y = HEIGHT - ((value - min) / span) * (HEIGHT - 4) - 2;

            return `${index === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');
});

const endPoint = computed(() => {
    const values = usable.value;
    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;
    const last = values[values.length - 1];

    return { x: WIDTH, y: HEIGHT - ((last - min) / span) * (HEIGHT - 4) - 2 };
});
</script>

<template>
    <svg
        v-if="hasShape"
        :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
        :class="toneStroke[tone] ?? toneStroke.neutral"
        class="h-7 w-24 overflow-visible"
        fill="none"
        aria-hidden="true"
    >
        <path :d="path" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        <circle :cx="endPoint.x" :cy="endPoint.y" r="2.5" fill="currentColor" />
    </svg>
</template>
