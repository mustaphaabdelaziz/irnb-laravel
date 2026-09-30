<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PeriodFilter from '@/Components/Activity/PeriodFilter.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import Icon from '@/Components/Icon.vue';
import { useCan } from '@/Composables/useCan';
import { pct, periodQuery } from '@/lib/attendanceStats';

/**
 * Players at risk (AtRisk on the server) for a period, the current season by
 * default, and an optional category (the players' current one). A player is
 * listed for a low score, an unexcused streak, or both.
 */
const props = defineProps({
    period: { type: Object, required: true },
    categoryId: { type: Number, default: null },
    categories: { type: Array, default: () => [] },
    thresholds: { type: Object, required: true }, // { min_score_pct, unexcused_streak, min_expected }
    rows: { type: Array, default: () => [] },
});
const { t } = useI18n();
const { can } = useCan();

const keep = computed(() => (props.categoryId ? { category_id: props.categoryId } : {}));
const exportHref = computed(() => route('attendance.alerts.export', { ...periodQuery(props.period), ...keep.value }));
const letterHref = (row) => route('attendance.players.letter', { player: row.player_id, ...periodQuery(props.period) });
function pickCategory(value) {
    router.get(route('attendance.alerts'), { ...periodQuery(props.period), ...(value ? { category_id: Number(value) } : {}) }, { preserveScroll: true, replace: true });
}

// The two rules as configured; a rule set to 0 reads "off".
const rules = computed(() => [
    props.thresholds.min_score_pct > 0
        ? t('att.risk.rule_score', { pct: props.thresholds.min_score_pct, n: props.thresholds.min_expected })
        : `${t('att.risk.flag.low_score')}: ${t('att.risk.rule_off')}`,
    props.thresholds.unexcused_streak > 0
        ? t('att.risk.rule_streak', { streak: props.thresholds.unexcused_streak })
        : `${t('att.risk.flag.streak')}: ${t('att.risk.rule_off')}`,
]);

const input = 'h-9 rounded-lg border-slate-300 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-900';
const card = 'rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
const linkButton = 'rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800';
const rowAction = 'inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800';
const th = 'p-2 font-semibold';
</script>

<template>
    <Head :title="t('att.risk.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('att.risk.title') }}</h1>
                <div class="flex items-center gap-2 print:hidden">
                    <Link :href="route('attendance.stats')" :class="linkButton">{{ t('att.statistics') }}</Link>
                    <ExportMenu :href="exportHref" :label="t('export')" :formats="['xlsx', 'csv']">
                        <template #icon><Icon name="download" /></template>
                    </ExportMenu>
                </div>
            </div>
        </template>

        <div class="space-y-5">
            <div class="flex flex-wrap items-center gap-3 print:hidden">
                <PeriodFilter :period="period" :href="route('attendance.alerts')" :keep="keep" />
                <select :value="categoryId ?? ''" :class="input" :aria-label="t('att.category')" @change="pickCategory($event.target.value)">
                    <option value="">{{ t('att.all_categories') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>

            <ul class="space-y-0.5 text-sm text-slate-600 dark:text-slate-300">
                <li v-for="(rule, i) in rules" :key="i" class="flex items-center gap-2"><Icon name="alert" class="size-4 text-slate-400" />{{ rule }}</li>
            </ul>

            <section :class="[card, 'overflow-x-auto']">
                <p v-if="!rows.length" class="p-6 text-center text-sm text-slate-500">{{ t('att.risk.none') }}</p>
                <table v-else class="w-full min-w-[64rem] text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800/50">
                            <th :class="[th, 'text-start']">{{ t('att.player') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.category') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.col.expected') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.col.score_pct') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.risk.col.unexcused') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.risk.col.current_streak') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.risk.col.longest_streak') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.risk.col.last_session') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.risk.col.reasons') }}</th>
                            <th :class="th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.player_id" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="whitespace-nowrap p-2 font-medium">
                                <Link v-if="can('players', 'view')" :href="route('players.show', row.player_id)" class="text-primary-700 hover:underline dark:text-primary-300">{{ row.name }}</Link>
                                <template v-else>{{ row.name }}</template>
                            </td>
                            <td class="whitespace-nowrap p-2 text-slate-500">{{ row.category ?? '—' }}</td>
                            <td class="p-2 text-end tabular-nums">{{ row.expected }}</td>
                            <td class="p-2 text-end font-semibold tabular-nums" :class="{ 'text-rose-600 dark:text-rose-400': row.low_score }"><bdi dir="ltr">{{ pct(row.score_pct) }}</bdi></td>
                            <td class="p-2 text-end tabular-nums">{{ row.unexcused }}</td>
                            <td class="p-2 text-end tabular-nums">{{ row.current_streak }}</td>
                            <td class="p-2 text-end tabular-nums" :class="{ 'font-semibold text-rose-600 dark:text-rose-400': row.streak }">{{ row.longest_streak }}</td>
                            <td class="whitespace-nowrap p-2"><bdi dir="ltr">{{ row.last_date ?? '—' }}</bdi></td>
                            <td class="p-2">
                                <span class="flex flex-wrap gap-1">
                                    <span v-if="row.low_score" class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-500/15 dark:text-rose-300">{{ t('att.risk.flag.low_score') }}</span>
                                    <span v-if="row.streak" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">{{ t('att.risk.flag.streak') }}</span>
                                </span>
                            </td>
                            <td class="whitespace-nowrap p-2 text-end">
                                <span class="inline-flex gap-1">
                                    <a :href="letterHref(row)" target="_blank" :class="rowAction"><Icon name="mail" />{{ t('att.letter.print') }}</a>
                                    <Link v-if="can('players', 'view')" :href="route('players.show', row.player_id)" :class="rowAction"><Icon name="user" />{{ t('att.risk.open_profile') }}</Link>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
