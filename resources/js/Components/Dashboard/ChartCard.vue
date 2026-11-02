<script setup>
import { computed, provide, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import IconButton from '@/Components/IconButton.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { useCurrency } from '@/Composables/useCurrency';
import { downloadCsv, toCsv } from '@/lib/statTable';

/**
 * The frame every chart sits in.
 *
 * Carries the states a chart actually has — loading, failed, empty, and
 * drawn — plus a table view. The table is not a nicety: three colours in the
 * palette sit below 3:1 contrast on the light surface, and the rule that
 * comes with them is that the values must be reachable as text.
 *
 * Switching to the table is local state only: no request, no filter change.
 * With `csv`, the panel also offers the table as a CSV file built from the
 * same rows.
 */
const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    empty: { type: Boolean, default: false },
    emptyHint: { type: String, default: null },
    loading: { type: Boolean, default: false },
    height: { type: String, default: 'h-64' },
    hasTable: { type: Boolean, default: false },
    error: { type: [Boolean, String], default: null },
    onRetry: { type: Function, default: null },
    csv: { type: Object, default: null },
});

const { t } = useI18n();
const { currencyLabel } = useCurrency();

// StatTable inside the table box must not open a second scroll box.
provide('statTableInScroller', true);

// One id per instance ties each tab to the panel it controls.
const uid = `cc-${useId()}`;
const view = ref('chart');
const showTable = computed(() => view.value === 'table');

const VIEWS = [
    { key: 'chart', icon: 'dashboard', label: 'stats.view_chart' },
    { key: 'table', icon: 'clipboard', label: 'stats.view_table' },
];

const failed = computed(() => props.error !== null && props.error !== false && props.error !== '');
const errorText = computed(() => (typeof props.error === 'string' && props.error !== '' ? props.error : t('stats.load_failed')));
const canDownload = computed(() => !!props.csv?.rows?.length && !props.loading && !failed.value && !props.empty);

function switchView(event) {
    view.value = view.value === 'chart' ? 'table' : 'chart';
    const group = event.currentTarget.parentElement;
    requestAnimationFrame(() => group.querySelector('[aria-selected="true"]')?.focus());
}

function download() {
    downloadCsv(props.csv.filename, toCsv(props.csv.columns, props.csv.rows, {
        currency: currencyLabel.value,
        totals: props.csv.totals ?? null,
        totalLabel: t('stats.total'),
    }));
}
</script>

<template>
    <Card class="min-w-0 border-border/70 shadow-none">
        <CardHeader class="flex-row flex-wrap items-start justify-between gap-3 space-y-0 px-5 pb-2 pt-4">
            <div class="min-w-0">
                <CardTitle class="text-base">{{ title }}</CardTitle>
                <CardDescription v-if="subtitle" class="mt-0.5">{{ subtitle }}</CardDescription>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <slot name="actions" />
                <IconButton
                    v-if="canDownload"
                    icon="download"
                    :label="t('stats.download_csv')"
                    variant="ghost"
                    size="sm"
                    @click="download"
                />
                <div
                    v-if="hasTable && !empty && !failed"
                    role="tablist"
                    :aria-label="t('stats.view')"
                    class="inline-flex rounded-lg bg-muted p-0.5 text-xs font-medium"
                >
                    <button
                        v-for="option in VIEWS"
                        :key="option.key"
                        type="button"
                        role="tab"
                        :id="`${uid}-tab-${option.key}`"
                        :aria-controls="`${uid}-panel`"
                        :aria-selected="view === option.key"
                        :tabindex="view === option.key ? 0 : -1"
                        class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :class="view === option.key ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                        @click="view = option.key"
                        @keydown.left.prevent="switchView"
                        @keydown.right.prevent="switchView"
                    >
                        <Icon :name="option.icon" class="size-3.5" />
                        <span>{{ t(option.label) }}</span>
                    </button>
                </div>
            </div>
        </CardHeader>

        <CardContent class="px-5 pb-5">
            <div v-if="loading" :class="height" class="animate-pulse rounded-lg bg-muted/60" />

            <div
                v-else-if="failed"
                :class="height"
                role="alert"
                class="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-rose-300/70 text-center dark:border-rose-500/30"
            >
                <p class="text-sm font-medium text-rose-700 dark:text-rose-400">{{ errorText }}</p>
                <button
                    v-if="onRetry"
                    type="button"
                    class="rounded text-xs font-semibold text-primary hover:underline dark:text-primary-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    @click="onRetry()"
                >
                    {{ t('stats.retry') }}
                </button>
            </div>

            <div
                v-else-if="empty"
                :class="height"
                class="flex flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-border text-center"
            >
                <p class="text-sm font-medium text-muted-foreground">{{ t('dashboard.no_data') }}</p>
                <p v-if="emptyHint" class="max-w-xs text-xs text-muted-foreground/80">{{ emptyHint }}</p>
            </div>

            <div
                v-else-if="showTable"
                :id="hasTable ? `${uid}-panel` : undefined"
                :role="hasTable ? 'tabpanel' : undefined"
                :aria-labelledby="hasTable ? `${uid}-tab-table` : undefined"
                class="max-h-72 overflow-auto rounded-lg border border-border/70"
            >
                <slot name="table" />
            </div>

            <div
                v-else
                :id="hasTable ? `${uid}-panel` : undefined"
                :role="hasTable ? 'tabpanel' : undefined"
                :aria-labelledby="hasTable ? `${uid}-tab-chart` : undefined"
                :class="height"
                class="relative min-w-0"
            >
                <slot />
            </div>
        </CardContent>
    </Card>
</template>
