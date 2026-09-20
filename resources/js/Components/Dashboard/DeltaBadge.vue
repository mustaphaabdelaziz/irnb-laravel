<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * Period-over-period change.
 *
 * The tone comes from the server, which knows whether rising is good for this
 * particular number — debt falling is green even though the arrow points down.
 * Colouring by the sign here instead would quietly invert the meaning of the
 * debt and expense tiles.
 */
const props = defineProps({
    delta: { type: Object, default: null },
    periodLabel: { type: String, default: '' },
});

const { t } = useI18n();

const toneClasses = {
    positive: 'text-emerald-700 dark:text-emerald-400',
    negative: 'text-rose-700 dark:text-rose-400',
    neutral: 'text-muted-foreground',
};

const rising = computed(() => (props.delta?.percent ?? 0) > 0);
const flat = computed(() => Math.abs(props.delta?.percent ?? 0) < 0.05);
const magnitude = computed(() => Math.abs(props.delta?.percent ?? 0).toFixed(1).replace(/\.0$/, ''));
</script>

<template>
    <!-- The comparison period is named once, in the filter bar, and repeated
         here only as a title. Printing it on six tiles wrapped the chip to
         three lines in Arabic and said the same thing six times. -->
    <p
        v-if="delta"
        class="mt-1.5 flex items-center gap-1 whitespace-nowrap text-xs font-medium"
        :class="toneClasses[delta.tone]"
        :title="periodLabel"
    >
        <svg class="size-3.5 shrink-0" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path
                v-if="flat"
                d="M3 8h10"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
            />
            <path
                v-else
                :d="rising ? 'M8 13V3m0 0L4 7m4-4 4 4' : 'M8 3v10m0 0 4-4m-4 4-4-4'"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>
        <span class="tabular-nums">{{ flat ? t('dashboard.delta_flat') : `${magnitude}%` }}</span>
    </p>
</template>
