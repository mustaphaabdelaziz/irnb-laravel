<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import IconButton from '@/Components/IconButton.vue';
import StatsToolbar from '@/Components/Stats/StatsToolbar.vue';

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

const emit = defineEmits(['loading', 'failed']);

const { t } = useI18n();

const RANGES = ['month', 'last_month', 'quarter', 'season', 'last12', 'all'];

const isAllTime = computed(() => props.filters.range === 'all');

// Filters that differ from the defaults (this month, all branches, compare on).
const activeCount = computed(() =>
    (props.filters.range !== 'month' ? 1 : 0)
    + (props.filters.branch ? 1 : 0)
    + (!props.filters.compare && props.filters.range !== 'all' ? 1 : 0));

function apply(changes) {
    // Inertia has no per-visit exception hook: a visit that finishes without
    // succeeding (network or server error) is a failure. A cancel means a newer
    // visit superseded this one, which is not an error.
    let ok = false;
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
            onStart: () => emit('loading', true),
            onSuccess: () => { ok = true; },
            onCancel: () => { ok = true; },
            onFinish: () => {
                emit('loading', false);
                if (!ok) emit('failed');
            },
        },
    );
}

function reset() {
    apply({ range: 'month', branch: '', compare: true });
}

const branchLabel = (branch) => branch.localized_name || branch.name;
</script>

<template>
    <StatsToolbar :active-count="activeCount" :loading="loading" @reset="reset">
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
        <IconButton
            icon="refresh"
            :label="t('dashboard.compare')"
            :pressed="!!filters.compare"
            :disabled="isAllTime"
            :title="isAllTime ? t('dashboard.compare_unavailable') : t('dashboard.compare')"
            @click="apply({ compare: !filters.compare })"
        />

        <span v-if="loading" class="text-xs text-muted-foreground">{{ t('dashboard.updating') }}</span>
    </StatsToolbar>
</template>
