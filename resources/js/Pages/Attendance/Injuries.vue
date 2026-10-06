<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import PeriodFilter from '@/Components/Activity/PeriodFilter.vue';
import { useCan } from '@/Composables/useCan';
import { parseDay } from '@/lib/attendanceCalendar';
import { periodQuery } from '@/lib/attendanceStats';

/**
 * The club's injuries (InjurySpells on the server): who is injured now,
 * whatever the period, and every spell in the period (the current season
 * by default), optionally for one category (the players' current one).
 * Details are added on each player's profile.
 */
const props = defineProps({
    period: { type: Object, required: true },
    categoryId: { type: Number, default: null },
    categories: { type: Array, default: () => [] },
    current: { type: Array, default: () => [] },
    spells: { type: Array, default: () => [] },
});
const { t, locale } = useI18n();
const { can } = useCan();

const keep = computed(() => (props.categoryId ? { category_id: props.categoryId } : {}));
function pickCategory(value) {
    router.get(route('attendance.injuries'), { ...periodQuery(props.period), ...(value ? { category_id: Number(value) } : {}) }, { preserveScroll: true, replace: true });
}
const day = (key) => parseDay(key).toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: 'numeric' });
const details = (row) => [row.note?.body_part, row.note?.description].filter(Boolean).join(' — ');

const sections = computed(() => [
    { key: 'current', title: t('att.injury.current'), rows: props.current, empty: t('att.injury.none_current') },
    { key: 'spells', title: t('att.injury.in_period'), rows: props.spells, empty: t('att.injury.none') },
]);

const input = 'h-9 rounded-lg border-slate-300 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-900';
const card = 'rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
const th = 'p-2 font-semibold';
</script>

<template>
    <Head :title="t('att.injury.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('att.injury.title') }}</h1>
                <IconButton :href="route('attendance.stats')" icon="dashboard" :label="t('att.statistics')" class="print:hidden" />
            </div>
        </template>

        <div class="space-y-5">
            <div class="flex flex-wrap items-center gap-3 print:hidden">
                <PeriodFilter :period="period" :href="route('attendance.injuries')" :keep="keep" />
                <select :value="categoryId ?? ''" :class="input" :aria-label="t('att.category')" @change="pickCategory($event.target.value)">
                    <option value="">{{ t('att.all_categories') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('att.injury.help') }}</p>

            <section v-for="section in sections" :key="section.key" :class="[card, 'overflow-x-auto']">
                <h2 class="px-4 pt-4 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ section.title }}</h2>
                <p v-if="!section.rows.length" class="p-6 text-center text-sm text-slate-500">{{ section.empty }}</p>
                <table v-else class="mt-2 w-full min-w-[48rem] text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800/50">
                            <th :class="[th, 'text-start']">{{ t('att.player') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.category') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.injury.col.start') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.injury.col.end') }}</th>
                            <th :class="[th, 'text-end']">{{ t('att.injury.col.sessions') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.injury.col.state') }}</th>
                            <th :class="[th, 'text-start']">{{ t('att.injury.col.description') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in section.rows" :key="`${row.player_id}-${row.start}`" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="whitespace-nowrap p-2 font-medium">
                                <Link v-if="can('players', 'view')" :href="route('players.show', row.player_id)" class="text-primary-700 hover:underline dark:text-primary-300">{{ row.name }}</Link>
                                <template v-else>{{ row.name }}</template>
                            </td>
                            <td class="whitespace-nowrap p-2 text-slate-500">{{ row.category ?? '—' }}</td>
                            <td class="whitespace-nowrap p-2">{{ day(row.start) }}</td>
                            <td class="whitespace-nowrap p-2">{{ row.open ? '…' : day(row.end) }}</td>
                            <td class="p-2 text-end tabular-nums">{{ row.sessions }}</td>
                            <td class="p-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="row.open ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'"
                                >{{ row.open ? t('att.injury.open') : t('att.injury.closed') }}</span>
                            </td>
                            <td class="max-w-[20rem] truncate p-2 text-slate-600 dark:text-slate-300" :title="details(row)">{{ details(row) || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
