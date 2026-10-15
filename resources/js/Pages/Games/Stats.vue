<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import SeasonSelect from '@/Components/Competitions/SeasonSelect.vue';
import StatsLeaders from '@/Components/Competitions/StatsLeaders.vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';

const { t } = useI18n();
const props = defineProps({ rows: Array, leaders: Object, season: Number, seasons: Array, teams: Array, competitions: Array, filters: Object });

const query = computed(() => ({ season: props.season, ...(props.filters.team_id ? { team_id: props.filters.team_id } : {}), ...(props.filters.competition_id ? { competition_id: props.filters.competition_id } : {}) }));
function reload(params) {
    router.get(route('games.stats'), { ...query.value, ...params }, { preserveState: true, replace: true });
}
const competitionOptions = computed(() => props.competitions
    .filter((c) => !props.filters.team_id || c.team_id === props.filters.team_id)
    .map((c) => ({ value: c.id, label: c.name })));
const exportHref = computed(() => route('games.stats.export', query.value));
</script>

<template>
    <Head :title="t('comp.stats.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('comp.stats.title') }}</h1>
                <div class="flex flex-wrap items-center gap-2">
                    <SeasonSelect :model-value="season" :seasons="seasons" @update:model-value="reload({ season: $event, competition_id: null })" />
                    <SearchableSelect :model-value="filters.team_id ?? ''" :options="teams" :placeholder="t('team')" class="w-56" @update:model-value="reload({ team_id: $event || null, competition_id: null })" />
                    <SearchableSelect :model-value="filters.competition_id ?? ''" :options="competitionOptions" :placeholder="t('comp.competition')" class="w-56" @update:model-value="reload({ competition_id: $event || null })" />
                    <ExportMenu :href="exportHref" :label="t('export')" :formats="['xlsx', 'csv', 'pdf']" />
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-6xl space-y-6">
            <StatsLeaders :leaders="leaders" />
            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950"><tr>
                        <th class="px-3 py-2 text-start">{{ t('comp.player') }}</th>
                        <th v-for="c in ['appearances', 'starts', 'minutes', 'goals', 'assists', 'yellows', 'reds', 'avg_rating', 'motm']" :key="c" class="px-3 py-2 text-center">{{ t(`comp.stats.col_${c}`) }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="r in rows" :key="`${r.player_id}-${r.sport}`">
                            <td class="px-3 py-2 font-medium">{{ r.name }} <span v-if="r.sport !== 'football'" class="text-xs text-slate-400">· {{ t(`comp.sport_${r.sport}`) }}</span></td>
                            <td class="px-3 py-2 text-center">{{ r.appearances }}</td>
                            <td class="px-3 py-2 text-center">{{ r.starts }}</td>
                            <td class="px-3 py-2 text-center"><bdi dir="ltr">{{ r.sport === 'volleyball' ? r.sets : r.minutes }}</bdi></td>
                            <td class="px-3 py-2 text-center">{{ r.goals }}</td>
                            <td class="px-3 py-2 text-center">{{ r.assists }}</td>
                            <td class="px-3 py-2 text-center">{{ r.yellows }}</td>
                            <td class="px-3 py-2 text-center">{{ r.reds }}</td>
                            <td class="px-3 py-2 text-center">{{ r.avg_rating ?? '—' }}</td>
                            <td class="px-3 py-2 text-center">{{ r.motm }}</td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="10" class="px-3 py-8 text-center text-slate-500">{{ t('no_results') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
