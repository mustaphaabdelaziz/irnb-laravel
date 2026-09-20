<script setup>
import { computed } from 'vue';

/**
 * One ratio against a limit.
 *
 * The track and the fill are steps of the same hue, so the bar reads as "how
 * much of this" rather than as two competing categories.
 */
const props = defineProps({
    value: { type: Number, default: 0 },
    max: { type: Number, default: 100 },
    label: { type: String, default: null },
    caption: { type: String, default: null },
});

const percent = computed(() => {
    if (!props.max) return 0;

    return Math.min(100, Math.max(0, (props.value / props.max) * 100));
});
</script>

<template>
    <div class="space-y-1.5">
        <div v-if="label || caption" class="flex items-baseline justify-between gap-3 text-sm">
            <span class="truncate text-muted-foreground">{{ label }}</span>
            <span v-if="caption" class="shrink-0 font-medium tabular-nums">{{ caption }}</span>
        </div>
        <div
            class="h-2 overflow-hidden rounded-full bg-primary/15"
            role="meter"
            :aria-valuenow="Math.round(percent)"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="label || undefined"
        >
            <div class="h-full rounded-full bg-primary transition-[width]" :style="{ width: `${percent}%` }" />
        </div>
    </div>
</template>
