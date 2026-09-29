<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Bar } from 'vue-chartjs';
import { useI18n } from 'vue-i18n';
import '@/lib/registerCharts';
import PeriodFilter from '@/Components/Activity/PeriodFilter.vue';
import StatDoughnut from '@/Components/StatDoughnut.vue';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';
import { baseOptions } from '@/lib/chartTheme';
import { dayLabel } from '@/lib/attendanceCalendar';
import { hours, pct, statusBars } from '@/lib/attendanceStats';

/**
 * The profile's attendance card. It fetches its own data
 * (attendance.players.show, gated on attendance/view) once the profile has
 * painted, so the profile never waits for it, and a period change reloads
 * only this card. Status names and colours come from the configured codes.
 */
const props = defineProps({
    player: { type: Object, required: true },
});

const { t, locale } = useI18n();
const { statuses, label, color, chipStyle } = useAttendanceCodes();
const rtl = computed(() => locale.value === 'ar');

const data = ref(null);
const loading = ref(true);
const failed = ref(false);
const lastQuery = ref({});
let ticket = 0; // a slow answer for an older period never overwrites a newer one

async function load(query = {}) {
    const mine = ++ticket;
    lastQuery.value = query;
    loading.value = true;
    failed.value = false;
    try {
        const response = await window.axios.get(route('attendance.players.show', { player: props.player.id, ...query }));
        if (mine === ticket) data.value = response.data;
    } catch {
        if (mine === ticket) failed.value = true;
    } finally {
        if (mine === ticket) loading.value = false;
    }
}
onMounted(() => load());
const retry = () => load(lastQuery.value);
defineExpose({ load });

const summary = computed(() => data.value?.summary ?? null);
const tiles = computed(() => (summary.value
    ? [
        { key: 'expected', label: t('att.col.expected'), value: summary.value.expected },
        { key: 'score', label: t('att.col.score_pct'), value: pct(summary.value.score_pct) },
        { key: 'late', label: t('att.col.late_minutes'), value: summary.value.late_minutes },
        { key: 'missed', label: t('att.col.missed_hours'), value: hours(summary.value.missed_hours) },
    ]
    : []));

const doughnutStats = computed(() => statuses.map((s) => ({ key: s, label: label(s), count: summary.value?.counts?.[s] ?? 0, static: true })));
const doughnutPalette = computed(() => statuses.map((s) => color(s)));
const monthlyChart = computed(() => statusBars(data.value?.monthly, statuses, label, color, locale.value));
const monthlyOptions = computed(() => baseOptions({ rtl: rtl.value, stacked: true }));

const preseasonText = computed(() => {
    const p = data.value?.preseason;
    if (!p) return null;
    return p.target
        ? t('att.preseason_progress', { season: p.season, done: p.done, target: p.target })
        : t('att.preseason_no_target', { season: p.season, done: p.done });
});
const reasonText = (row) => (row.reason ? t(`att.reason.${row.reason}`) : '');
const th = 'p-2 text-start text-xs font-semibold text-slate-500 dark:text-slate-400';
</script>

<template>
    <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('attendance') }}</h3>
            <PeriodFilter v-if="data" :period="data.period" @change="load" />
        </div>

        <div v-if="loading && !data" class="space-y-3 px-5 py-4" aria-busy="true">
            <div class="h-20 animate-pulse rounded-xl bg-slate-100 dark:bg-slate-800"></div>
            <div class="h-44 animate-pulse rounded-xl bg-slate-100 dark:bg-slate-800"></div>
        </div>

        <div v-else-if="failed" class="flex flex-col items-center gap-2 px-5 py-8 text-sm text-slate-500">
            <p>{{ t('att.profile.load_error') }}</p>
            <button type="button" class="rounded-lg px-3 py-1.5 font-semibold text-primary-700 ring-1 ring-primary-300 hover:bg-primary-50 dark:text-primary-300 dark:ring-primary-700" @click="retry">{{ t('att.retry') }}</button>
        </div>

        <div v-else-if="data" class="space-y-5 px-5 py-4 transition-opacity" :class="{ 'opacity-60': loading }">
            <div class="grid gap-3 sm:grid-cols-4">
                <div v-for="tile in tiles" :key="tile.key" class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ tile.label }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100"><bdi dir="ltr">{{ tile.value }}</bdi></p>
                </div>
            </div>
            <p v-if="preseasonText" class="text-sm text-slate-600 dark:text-slate-300">{{ preseasonText }}</p>

            <p v-if="!summary.expected" class="py-6 text-center text-sm text-slate-500">{{ t('att.stats.no_data') }}</p>
            <template v-else>
                <div class="grid gap-4 lg:grid-cols-2">
                    <StatDoughnut :title="t('att.profile.by_status')" :stats="doughnutStats" :palette="doughnutPalette" :unit="t('att.sessions_unit')" />
                    <div class="rounded-2xl p-4 ring-1 ring-slate-200 dark:ring-slate-800">
                        <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">{{ t('att.stats.by_month') }}</p>
                        <div class="h-52"><Bar :data="monthlyChart" :options="monthlyOptions" /></div>
                    </div>
                </div>

                <div>
                    <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('att.profile.sessions') }}</h4>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full min-w-[48rem] text-sm">
                            <thead>
                                <tr class="bg-slate-50 dark:bg-slate-800/50">
                                    <th :class="th">{{ t('att.date') }}</th>
                                    <th :class="th">{{ t('att.col.kind') }}</th>
                                    <th :class="th">{{ t('att.title_goal') }}</th>
                                    <th :class="th">{{ t('att.categories') }}</th>
                                    <th :class="th">{{ t('att.col.status') }}</th>
                                    <th :class="[th, 'text-end']">{{ t('att.minutes') }}</th>
                                    <th :class="th">{{ t('att.reason') }}</th>
                                    <th :class="th">{{ t('att.note') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in data.sessions" :key="row.session_id" class="border-t border-slate-100 dark:border-slate-800">
                                    <td class="whitespace-nowrap p-2 capitalize">
                                        <Link :href="route('attendance.sessions.show', row.session_id)" class="text-primary-700 hover:underline dark:text-primary-300">{{ dayLabel(row.date, locale) }}</Link>
                                        <span dir="ltr" class="ms-1 text-xs text-slate-400">{{ row.start_time }}</span>
                                    </td>
                                    <td class="whitespace-nowrap p-2">{{ t(`att.kind.${row.kind}`) }}</td>
                                    <td class="max-w-[14rem] truncate p-2" :title="row.title ?? ''">{{ row.title }}</td>
                                    <td class="p-2 text-slate-500">{{ row.categories.join(' · ') }}</td>
                                    <td class="p-2"><span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold" :style="chipStyle(row.status)">{{ label(row.status) }}</span></td>
                                    <td class="p-2 text-end tabular-nums">{{ row.minutes ?? '' }}</td>
                                    <td class="p-2">{{ reasonText(row) }}</td>
                                    <td class="p-2 text-slate-500">{{ row.note }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
