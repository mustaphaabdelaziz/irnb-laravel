<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Card } from '@/Components/ui/card';
import { useStatSelection } from '@/Composables/useStatSelection';

/**
 * A distribution as ranked rows: label, bar, count, share. For dimensions
 * with more values than a doughnut can show (categories, positions, ages,
 * family names) — aligned bars and numbers compare at a glance, thin slices
 * do not. Same props and click-to-filter v-model as StatDoughnut.
 */
const props = defineProps({
    title: { type: String, required: true },
    // [{ key, label, count, static? }] — see useStatSelection.
    stats: { type: Array, default: () => [] },
    modelValue: { type: [String, Number, Array], default: '' },
    // What the total counts; players by default.
    unit: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);

const { t } = useI18n();
const { total, pct, clickable, isActive, toggle, hasSelection, selectedTotal, filtered, clear } = useStatSelection(props, emit);

// Bars are scaled to the biggest row, not to the total: with many small rows
// a share-of-total bar is a sliver nobody can compare.
const max = computed(() => Math.max(1, ...props.stats.map((s) => s.count)));
const width = (count) => `${Math.max(2, Math.round((count / max.value) * 100))}%`;
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

        <!-- Capped height: a long list scrolls inside its card instead of
             stretching the whole row. -->
        <ul v-else class="-mx-2 max-h-48 space-y-0.5 overflow-y-auto">
            <li v-for="stat in stats" :key="String(stat.key)">
                <component
                    :is="clickable(stat) ? 'button' : 'div'"
                    :type="clickable(stat) ? 'button' : undefined"
                    :aria-pressed="clickable(stat) ? isActive(stat) : undefined"
                    @click="toggle(stat)"
                    class="grid w-full grid-cols-[minmax(0,2fr)_minmax(0,3fr)_auto] items-center gap-3 rounded-md px-2 py-1.5 text-start text-sm transition-colors"
                    :class="[
                        clickable(stat) ? 'hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500 dark:hover:bg-slate-800' : '',
                        isActive(stat) ? 'bg-primary-50 hover:bg-primary-50 dark:bg-primary-500/10 dark:hover:bg-primary-500/10' : '',
                    ]"
                >
                    <span class="truncate" :title="stat.label"
                        :class="isActive(stat)
                            ? 'font-semibold text-primary-800 dark:text-primary-200'
                            : clickable(stat) ? 'text-slate-700 dark:text-slate-200' : 'text-slate-500 dark:text-slate-400'">
                        {{ stat.label }}
                    </span>
                    <span class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" aria-hidden="true">
                        <!-- With a selection, the rows left out turn grey so the chosen ones stand out. -->
                        <span class="block h-full rounded-full transition-[width]"
                            :class="!clickable(stat) || (hasSelection && !isActive(stat)) ? 'bg-slate-300 dark:bg-slate-600' : 'bg-primary-500'"
                            :style="{ width: width(stat.count) }"></span>
                    </span>
                    <span class="flex items-baseline gap-2 tabular-nums">
                        <span class="min-w-6 text-end font-semibold text-slate-900 dark:text-slate-100">{{ stat.count }}</span>
                        <span class="w-9 text-end text-xs text-slate-500 dark:text-slate-400">{{ pct(stat.count) }}%</span>
                    </span>
                </component>
            </li>
        </ul>
    </Card>
</template>
