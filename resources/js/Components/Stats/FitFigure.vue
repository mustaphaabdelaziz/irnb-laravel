<script setup>
import { computed } from 'vue';
import { figureChars } from '@/lib/numberFormat';

/**
 * A figure that stays on one line and shrinks to fit: "372 500,00" must never
 * break into "372 50" and "0,00". The nearest ancestor with
 * [container-type:inline-size] sets the room it has. Text with words wraps.
 */
const props = defineProps({
    value: { type: [String, Number], default: null },
});

const chars = computed(() => figureChars(props.value));
</script>

<template>
    <span :class="{ 'figure-fit': chars }" :style="chars ? { '--figure-chars': chars } : null">{{ value }}</span>
</template>
