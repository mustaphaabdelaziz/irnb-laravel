<script setup>
import { useI18n } from 'vue-i18n';
import IconButton from '@/Components/IconButton.vue';

/**
 * The bar a stats page's filters sit in. It owns the look — spacing, the
 * active-filter count, Reset, the busy state — never the filters: each page
 * keeps its own controls and the requests they make.
 */
defineProps({
    activeCount: { type: Number, default: 0 },
    loading: { type: Boolean, default: false },
    onReset: { type: Function, default: null },
});

const { t } = useI18n();
</script>

<template>
    <div
        role="group"
        :aria-label="t('stats.filters')"
        :aria-busy="loading"
        class="flex flex-wrap items-center gap-2 rounded-xl border border-border/70 bg-card px-3 py-2"
    >
        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
            <slot />
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <span
                v-if="loading"
                class="size-4 animate-spin rounded-full border-2 border-muted-foreground/30 border-t-primary"
                aria-hidden="true"
            />
            <span
                v-if="activeCount > 0"
                class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold tabular-nums text-primary"
            >{{ t('stats.active_filters', { count: activeCount }) }}</span>
            <IconButton
                v-if="onReset && activeCount > 0"
                icon="filteroff"
                :label="t('reset')"
                variant="ghost"
                size="sm"
                @click="onReset()"
            />
            <slot name="actions" />
        </div>
    </div>
</template>
