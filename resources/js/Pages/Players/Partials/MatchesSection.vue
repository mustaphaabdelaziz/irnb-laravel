<script setup>
import SeasonSelect from '@/Components/Competitions/SeasonSelect.vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { onMounted, ref } from 'vue';

// Loaded on its own, like the attendance section, so the player page stays fast.
const { t } = useI18n();
const props = defineProps({ player: Object });
const data = ref(null);
const loading = ref(true);
const failed = ref(false);

async function load(season = null) {
    loading.value = true;
    failed.value = false;
    try {
        const response = await window.axios.get(route('games.players.show', { player: props.player.id, ...(season ? { season } : {}) }));
        data.value = response.data;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}
onMounted(() => load());
const sanctionLabel = (key) => {
    const label = t(`comp.sanction.${key}`);
    return label === `comp.sanction.${key}` ? key : label;
};
</script>

<template>
    <section class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-semibold">{{ t('games') }}</h2>
            <SeasonSelect v-if="data" :model-value="data.season" :seasons="data.seasons" @update:model-value="load($event)" />
        </div>
        <p v-if="failed" class="text-sm text-rose-600 dark:text-rose-400">{{ t('comp.load_failed') }}</p>
        <p v-if="loading && !data" class="text-sm text-slate-500">…</p>
        <template v-else-if="data">
            <div v-for="s in data.suspensions" :key="s.id" class="rounded-lg bg-rose-50 p-2 text-sm text-rose-800 dark:bg-rose-900/30 dark:text-rose-200">
                ⛔ {{ t('comp.flag.suspended') }} — {{ s.until ? t('comp.flag.suspended_until', { date: s.until }) : t('comp.flag.suspended_left', { n: s.remaining }) }}
                <span v-if="s.competition">· {{ s.competition }}</span><span v-if="s.reason"> · {{ s.reason }}</span>
            </div>
            <div v-for="row in data.totals" :key="row.sport" class="grid grid-cols-3 gap-2 text-center text-sm sm:grid-cols-9">
                <div v-for="c in ['appearances', 'starts', row.sport === 'volleyball' ? 'sets' : 'minutes', 'goals', 'assists', 'yellows', 'reds', 'avg_rating', 'motm']" :key="c" class="rounded-lg bg-slate-50 p-2 dark:bg-slate-800">
                    <div class="text-lg font-bold">{{ row[c] ?? '—' }}</div>
                    <div class="text-xs text-slate-500">{{ t(`comp.stats.col_${c}`) }}</div>
                </div>
            </div>
            <table v-if="data.games.length" class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="g in data.games" :key="g.game_id">
                        <td class="py-1"><bdi dir="ltr" class="font-mono text-xs">{{ g.date }}</bdi></td>
                        <td class="py-1"><Link :href="route('games.show', g.game_id)" class="hover:underline">{{ g.team }} — {{ g.opponent }}</Link>
                            <span class="text-xs text-slate-400"> · {{ g.competition || t('comp.type_friendly') }}</span></td>
                        <td class="py-1 font-mono"><bdi dir="ltr">{{ g.our_score }}–{{ g.their_score }}</bdi></td>
                        <td class="py-1 text-xs">{{ t(g.role === 'starter' ? 'comp.starter' : 'comp.substitute') }} · <bdi dir="ltr">{{ g.sets ?? g.minutes ?? 0 }}{{ g.sets === null ? '′' : '' }}</bdi></td>
                        <td class="py-1 text-xs"><span v-if="g.goals">⚽×{{ g.goals }} </span><span v-if="g.assists">🅰×{{ g.assists }} </span><span v-for="(c, i) in g.cards" :key="i">{{ sanctionLabel(c) }} </span><span v-if="g.motm">⭐</span></td>
                        <td class="py-1 text-xs text-slate-500">{{ g.rating ?? '' }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="text-sm text-slate-500">{{ t('comp.no_matches_yet') }}</p>
        </template>
    </section>
</template>
