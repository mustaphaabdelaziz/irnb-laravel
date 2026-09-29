<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';
import { hours, pct } from '@/lib/attendanceStats';

/** Every player of the period, sortable by any column (sorted in the browser: the server sends them all). */
const props = defineProps({
    rows: { type: Array, default: () => [] },
});
const { t, locale } = useI18n();
const { can } = useCan();
const { statuses, label, color } = useAttendanceCodes();

const TEXT = ['name', 'category'];
const sortKey = ref('score_pct');
const sortDir = ref('desc');

function value(row, key) {
    if (statuses.includes(key)) return row.counts[key];
    if (TEXT.includes(key)) return row[key] ?? '';
    return row[key] ?? -1; // no score % (nothing expected) sorts last in descending order
}

function sortBy(key) {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
        return;
    }
    sortKey.value = key;
    sortDir.value = TEXT.includes(key) ? 'asc' : 'desc';
}

const sorted = computed(() => {
    const dir = sortDir.value === 'asc' ? 1 : -1;
    const text = TEXT.includes(sortKey.value);
    return [...props.rows].sort((a, b) => {
        const x = value(a, sortKey.value);
        const y = value(b, sortKey.value);
        const order = text ? String(x).localeCompare(String(y), locale.value) : x - y;
        return order * dir || a.name.localeCompare(b.name, locale.value);
    });
});

const ariaSort = (key) => (sortKey.value !== key ? 'none' : sortDir.value === 'asc' ? 'ascending' : 'descending');

const columns = computed(() => [
    { key: 'name', label: t('att.player'), start: true },
    { key: 'category', label: t('att.category'), start: true },
    { key: 'expected', label: t('att.col.expected') },
    ...statuses.map((s) => ({ key: s, label: label(s), color: color(s) })),
    { key: 'late_minutes', label: t('att.col.late_minutes') },
    { key: 'missed_hours', label: t('att.col.missed_hours') },
    { key: 'score_pct', label: t('att.col.score_pct') },
]);
</script>

<template>
    <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <h2 class="px-4 pt-4 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('att.stats.players') }}</h2>
        <p v-if="!rows.length" class="p-6 text-center text-sm text-slate-500">{{ t('att.stats.no_data') }}</p>
        <table v-else class="mt-2 w-full min-w-[64rem] text-sm">
            <thead>
                <tr class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800/50">
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        :aria-sort="ariaSort(col.key)"
                        class="p-2 font-semibold"
                        :class="col.start ? 'text-start' : 'text-end'"
                    >
                        <button type="button" class="inline-flex items-center gap-1 hover:text-slate-900 dark:hover:text-slate-100" @click="sortBy(col.key)">
                            <span v-if="col.color" class="h-2 w-2 rounded-full" :style="{ backgroundColor: col.color }"></span>
                            {{ col.label }}
                            <span v-if="sortKey === col.key" aria-hidden="true">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
                        </button>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in sorted" :key="row.player_id" class="border-t border-slate-100 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/40">
                    <td class="whitespace-nowrap p-2 font-medium">
                        <Link v-if="can('players', 'view')" :href="route('players.show', row.player_id)" class="text-primary-700 hover:underline dark:text-primary-300">{{ row.name }}</Link>
                        <template v-else>{{ row.name }}</template>
                    </td>
                    <td class="whitespace-nowrap p-2 text-slate-500">{{ row.category ?? '—' }}</td>
                    <td class="p-2 text-end tabular-nums">{{ row.expected }}</td>
                    <td v-for="s in statuses" :key="s" class="whitespace-nowrap p-2 text-end tabular-nums">
                        {{ row.counts[s] }} <bdi dir="ltr" class="text-xs text-slate-400">{{ pct(row.pct[s]) }}</bdi>
                    </td>
                    <td class="p-2 text-end tabular-nums">{{ row.late_minutes }}</td>
                    <td class="p-2 text-end tabular-nums">{{ hours(row.missed_hours) }}</td>
                    <td class="p-2 text-end font-semibold tabular-nums"><bdi dir="ltr">{{ pct(row.score_pct) }}</bdi></td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
