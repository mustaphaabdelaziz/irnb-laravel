<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan';
import { pct } from '@/lib/attendanceStats';

/** Top and bottom 5 by score % (AttendanceStats::ranking). */
const props = defineProps({
    ranking: { type: Object, required: true }, // { min_expected, top: [], bottom: [] }
});
const { t } = useI18n();
const { can } = useCan();

const lists = computed(() => [
    { key: 'top', rows: props.ranking.top, tone: 'text-emerald-600 dark:text-emerald-400' },
    { key: 'bottom', rows: props.ranking.bottom, tone: 'text-rose-600 dark:text-rose-400' },
]);
</script>

<template>
    <section class="rounded-xl bg-white p-4 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('att.stats.ranking_help', { n: ranking.min_expected }) }}</p>
        <p v-if="!ranking.top.length" class="py-4 text-center text-sm text-slate-500">{{ t('att.stats.no_ranking') }}</p>
        <div v-else class="mt-3 grid gap-4 md:grid-cols-2">
            <div v-for="list in lists" :key="list.key">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t(`att.stats.${list.key}`) }}</h3>
                <p v-if="!list.rows.length" class="mt-2 text-sm text-slate-400">—</p>
                <ol v-else class="mt-2 space-y-1">
                    <li v-for="(row, i) in list.rows" :key="row.player_id" class="flex items-center gap-2 text-sm">
                        <span class="w-5 text-end tabular-nums text-slate-400">{{ i + 1 }}</span>
                        <Link v-if="can('players', 'view')" :href="route('players.show', row.player_id)" class="font-medium text-primary-700 hover:underline dark:text-primary-300">{{ row.name }}</Link>
                        <span v-else class="font-medium">{{ row.name }}</span>
                        <span class="text-xs text-slate-400">{{ row.category ?? '' }}</span>
                        <bdi dir="ltr" class="ms-auto font-semibold tabular-nums" :class="list.tone">{{ pct(row.score_pct) }}</bdi>
                    </li>
                </ol>
            </div>
        </div>
    </section>
</template>
