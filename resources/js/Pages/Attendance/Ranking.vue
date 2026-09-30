<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useCan } from '@/Composables/useCan';
import { pct } from '@/lib/attendanceStats';

/**
 * One category's attendance ranking for a month or a season
 * (AttendanceStats::ranked on the server: score %, then fewer unexcused,
 * then fewer lates). Players under the minimum are counted, not ranked.
 */
const props = defineProps({
    categories: { type: Array, default: () => [] },
    categoryId: { type: Number, default: null },
    period: { type: Object, required: true }, // { type, month, season, from, to, label }
    seasons: { type: Array, default: () => [] }, // [{ start_year, label }]
    rows: { type: Array, default: () => [] },
    unranked: { type: Number, default: 0 },
    minExpected: { type: Number, required: true },
});
const { t } = useI18n();
const { can } = useCan();

// The page's filters as they travel in a URL; `changes` overrides them.
function query(changes = {}) {
    const q = { category_id: props.categoryId, type: props.period.type, ...changes };
    if (q.type === 'season') q.season = changes.season ?? props.period.season;
    else q.month = changes.month ?? props.period.month;
    return q;
}
const visit = (changes) => router.get(route('attendance.ranking'), query(changes), { preserveScroll: true, replace: true });

// Gold, silver and bronze for the podium.
const MEDAL = {
    1: 'bg-amber-400 text-amber-950',
    2: 'bg-slate-300 text-slate-800',
    3: 'bg-orange-300 text-orange-950',
};

const input = 'h-9 rounded-lg border-slate-300 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-900';
const card = 'rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
const linkButton = 'rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800';
const th = 'p-2 font-semibold';
</script>

<template>
    <Head :title="t('att.ranking.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('att.ranking.title') }}</h1>
                <div class="flex items-center gap-2 print:hidden">
                    <Link :href="route('attendance.stats')" :class="linkButton">{{ t('att.statistics') }}</Link>
                </div>
            </div>
        </template>

        <div class="space-y-5">
            <div class="flex flex-wrap items-center gap-3 print:hidden">
                <select :value="categoryId ?? ''" :class="input" :aria-label="t('att.category')" @change="visit({ category_id: Number($event.target.value) })">
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <select :value="period.type" :class="input" :aria-label="t('activity.period_label')" @change="visit({ type: $event.target.value })">
                    <option value="month">{{ t('att.ranking.type.month') }}</option>
                    <option value="season">{{ t('att.ranking.type.season') }}</option>
                </select>
                <input v-if="period.type === 'month'" type="month" :value="period.month" :class="input" :aria-label="t('att.ranking.type.month')" @change="$event.target.value && visit({ month: $event.target.value })" />
                <select v-else :value="period.season" :class="input" :aria-label="t('att.ranking.type.season')" @change="visit({ season: Number($event.target.value) })">
                    <option v-for="s in seasons" :key="s.start_year" :value="s.start_year">{{ s.label }}</option>
                </select>
                <span class="text-sm text-slate-500 dark:text-slate-400">{{ period.label }}</span>
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('att.ranking.help', { n: minExpected }) }}</p>

            <section :class="[card, 'overflow-x-auto']">
                <p v-if="!rows.length" class="p-6 text-center text-sm text-slate-500">{{ t('att.ranking.none') }}</p>
                <table v-else class="w-full min-w-[40rem] text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800/50">
                            <th :class="[th, 'w-16 text-center']">{{ t('att.ranking.col.rank') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.player') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.col.expected') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.ranking.col.present') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.col.score_pct') }}</th>
                            <th :class="th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.player_id" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="p-2 text-center">
                                <span v-if="MEDAL[row.rank]" class="inline-flex size-7 items-center justify-center rounded-full text-xs font-bold" :class="MEDAL[row.rank]">{{ row.rank }}</span>
                                <span v-else class="tabular-nums text-slate-500">{{ row.rank }}</span>
                            </td>
                            <td class="whitespace-nowrap p-2 font-medium">
                                <Link v-if="can('players', 'view')" :href="route('players.show', row.player_id)" class="text-primary-700 hover:underline dark:text-primary-300">{{ row.name }}</Link>
                                <template v-else>{{ row.name }}</template>
                            </td>
                            <td class="p-2 text-end tabular-nums">{{ row.expected }}</td>
                            <td class="p-2 text-end tabular-nums">{{ row.present }}</td>
                            <td class="p-2 text-end font-semibold tabular-nums"><bdi dir="ltr">{{ pct(row.score_pct) }}</bdi></td>
                            <td class="whitespace-nowrap p-2 text-end"></td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="unranked" class="px-4 pb-3 pt-2 text-xs text-slate-500">{{ t('att.ranking.unranked', { count: unranked, n: minExpected }) }}</p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
