<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DashboardFilterBar from '@/Components/Dashboard/DashboardFilterBar.vue';
import StatTile from '@/Components/Dashboard/StatTile.vue';
import Icon from '@/Components/Icon.vue';
import { Button } from '@/Components/ui/button';
import FinanceTab from '@/Pages/Dashboard/Partials/FinanceTab.vue';
import MembersTab from '@/Pages/Dashboard/Partials/MembersTab.vue';
import OperationsTab from '@/Pages/Dashboard/Partials/OperationsTab.vue';
import OverviewTab from '@/Pages/Dashboard/Partials/OverviewTab.vue';
import '@/lib/registerCharts';

/**
 * The dashboard shell.
 *
 * Filters and the hero row stay put; only the tab below them changes. A tab's
 * data is fetched the first time it is opened and kept for the session, so
 * moving between tabs costs one request each rather than one per visit — and
 * the first paint never pays for the three tabs nobody is looking at.
 */
const props = defineProps({
    filters: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({ overview: true }) },
    hero: { type: Array, default: () => [] },
    overview: { type: Object, default: null },
    finance: { type: Object, default: null },
    members: { type: Object, default: null },
    operations: { type: Object, default: null },
});

const { t, locale } = useI18n();

const rtl = computed(() => locale.value === 'ar');

const activeTab = ref(props.filters.tab ?? 'overview');
const loadingTab = ref(false);

// Tabs already fetched this session. The server is the source of truth for the
// data; this only avoids asking twice for something that has not changed.
const loaded = reactive({ overview: props.overview !== null });

const TABS = ['overview', 'finance', 'members', 'operations'];

const visibleTabs = computed(() => TABS.filter((tab) => props.can[tab] !== false));

function selectTab(tab) {
    if (tab === activeTab.value) return;
    activeTab.value = tab;

    if (loaded[tab]) {
        // Keep the URL honest even when nothing needs fetching.
        router.get(route('dashboard'), { ...queryFromFilters(), tab }, {
            only: ['filters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            showProgress: false,
        });

        return;
    }

    loadingTab.value = true;
    router.get(route('dashboard'), { ...queryFromFilters(), tab }, {
        only: ['filters', tab],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        showProgress: false,
        onSuccess: () => { loaded[tab] = true; },
        onFinish: () => { loadingTab.value = false; },
    });
}

function queryFromFilters() {
    return {
        range: props.filters.range,
        branch: props.filters.branch || undefined,
        compare: props.filters.compare ? 1 : 0,
    };
}

// A filter change invalidates every tab that was fetched under the old one.
watch(
    () => [props.filters.range, props.filters.branch, props.filters.compare],
    () => {
        Object.keys(loaded).forEach((tab) => {
            loaded[tab] = tab === activeTab.value;
        });
    },
);

/** Presentation for each hero tile. The server names the tile; this dresses it. */
const TILE_STYLE = {
    members: { icon: 'players', tone: 'primary' },
    collection_rate: { icon: 'check', tone: 'positive' },
    net_cash_flow: { icon: 'money', tone: 'primary' },
    outstanding_debt: { icon: 'alert', tone: 'warning' },
    equipment_on_loan: { icon: 'box', tone: 'neutral' },
    treasury: { icon: 'money', tone: 'positive' },
};

const periodLabel = computed(() => t(`dashboard.vs_${props.filters.range}`));

const heroTiles = computed(() => props.hero.map((tile) => ({
    ...tile,
    label: t(`dashboard.kpi_${tile.key}`),
    icon: TILE_STYLE[tile.key]?.icon ?? 'dot',
    tone: TILE_STYLE[tile.key]?.tone ?? 'primary',
    meta: tile.meta?.overdue ? t('dashboard.overdue_count', { count: tile.meta.overdue }) : null,
})));
</script>

<template>
    <Head :title="t('dashboard')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex w-full flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold tracking-tight text-foreground">{{ t('dashboard') }}</h1>
                    <p class="hidden text-xs text-muted-foreground sm:block">{{ t('dashboard.subtitle') }}</p>
                </div>

                <div class="flex items-center gap-2">
                    <DashboardFilterBar
                        :filters="filters"
                        :branches="branches"
                        :active-tab="activeTab"
                        :loading="loadingTab"
                    />
                    <a :href="route('reports.financial')" target="_blank" rel="noopener noreferrer">
                        <Button as="span" variant="outline" size="sm" class="h-9">
                            <Icon name="document" /> PDF
                        </Button>
                    </a>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <section
                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6"
                :aria-label="t('dashboard.key_figures')"
            >
                <StatTile
                    v-for="tile in heroTiles"
                    :key="tile.key"
                    :label="tile.label"
                    :value="tile.value"
                    :format="tile.format"
                    :delta="tile.delta"
                    :spark="tile.spark"
                    :icon="tile.icon"
                    :tone="tile.tone"
                    :meta="tile.meta"
                    :period-label="periodLabel"
                />
            </section>

            <div class="border-b border-border/70">
                <nav class="-mb-px flex gap-1 overflow-x-auto" :aria-label="t('dashboard.sections')">
                    <button
                        v-for="tab in visibleTabs"
                        :key="tab"
                        type="button"
                        class="whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium transition-colors"
                        :class="activeTab === tab
                            ? 'border-primary text-primary'
                            : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground'"
                        :aria-current="activeTab === tab ? 'page' : undefined"
                        @click="selectTab(tab)"
                    >
                        {{ t(`dashboard.tab_${tab}`) }}
                    </button>
                </nav>
            </div>

            <OverviewTab v-if="activeTab === 'overview'" :data="overview" :loading="loadingTab" :rtl="rtl" />
            <FinanceTab v-else-if="activeTab === 'finance'" :data="finance" :loading="loadingTab" :rtl="rtl" />
            <MembersTab v-else-if="activeTab === 'members'" :data="members" :loading="loadingTab" :rtl="rtl" />
            <OperationsTab v-else :data="operations" :loading="loadingTab" :rtl="rtl" />
        </div>
    </AuthenticatedLayout>
</template>
