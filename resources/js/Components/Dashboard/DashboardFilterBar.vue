<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import { Button } from '@/Components/ui/button';

/**
 * The dashboard's controls, and the only thing that writes its URL.
 *
 * Filter state lives in the query string rather than in component state, so a
 * filtered view survives a refresh, can be linked to a colleague, and backs
 * out with the browser's back button. The reload asks only for the props that
 * actually depend on the filters.
 */
const props = defineProps({
    filters: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    activeTab: { type: String, default: 'overview' },
    loading: { type: Boolean, default: false },
});

const { t } = useI18n();

const RANGES = ['month', 'last_month', 'quarter', 'year', 'last12', 'all'];

const isAllTime = computed(() => props.filters.range === 'all');

function apply(changes) {
    router.get(
        route('dashboard'),
        {
            range: changes.range ?? props.filters.range,
            branch: (changes.branch ?? props.filters.branch) || undefined,
            compare: (changes.compare ?? props.filters.compare) ? 1 : 0,
            tab: props.activeTab,
        },
        {
            only: ['filters', 'hero', props.activeTab],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            showProgress: false,
        },
    );
}

const branchLabel = (branch) => branch.localized_name || branch.name;
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <div class="flex items-center gap-1.5">
            <Icon name="calendar" class="size-4 text-muted-foreground" />
            <select
                class="h-9 rounded-lg border border-input bg-background px-2 text-sm font-medium focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                :value="filters.range"
                :aria-label="t('dashboard.time_range')"
                @change="apply({ range: $event.target.value })"
            >
                <option v-for="range in RANGES" :key="range" :value="range">
                    {{ t(`dashboard.range_${range}`) }}
                </option>
            </select>
        </div>

        <div v-if="branches.length > 1" class="flex items-center gap-1.5">
            <Icon name="location" class="size-4 text-muted-foreground" />
            <select
                class="h-9 rounded-lg border border-input bg-background px-2 text-sm font-medium focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                :value="filters.branch ?? ''"
                :aria-label="t('dashboard.branch')"
                @change="apply({ branch: $event.target.value })"
            >
                <option value="">{{ t('dashboard.all_branches') }}</option>
                <option v-for="branch in branches" :key="branch.id" :value="branch.id">
                    {{ branchLabel(branch) }}
                </option>
            </select>
        </div>

        <!-- All-time has no previous period, so the control says why it is off
             rather than silently producing nothing. -->
        <Button
            variant="outline"
            size="sm"
            class="h-9 gap-1.5"
            :class="filters.compare ? 'border-primary/40 text-primary' : 'text-muted-foreground'"
            :disabled="isAllTime"
            :title="isAllTime ? t('dashboard.compare_unavailable') : t('dashboard.compare')"
            :aria-pressed="filters.compare"
            @click="apply({ compare: !filters.compare })"
        >
            <Icon name="refresh" class="size-4" />
            {{ t('dashboard.compare') }}
        </Button>

        <span v-if="loading" class="text-xs text-muted-foreground">{{ t('dashboard.updating') }}</span>
    </div>
</template>
