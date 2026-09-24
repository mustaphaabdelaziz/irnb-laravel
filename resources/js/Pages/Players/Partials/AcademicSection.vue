<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Line } from 'vue-chartjs';
import { useI18n } from 'vue-i18n';
import '@/lib/registerCharts';
import { baseOptions, lineDataset, mutedInk } from '@/lib/chartTheme';
import { PERIODS, yearLabel } from '@/lib/academic';
import { useCan } from '@/Composables/useCan';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Icon from '@/Components/Icon.vue';
import AcademicGradeModal from '@/Pages/Players/Partials/AcademicGradeModal.vue';
import AcademicYearModal from '@/Pages/Players/Partials/AcademicYearModal.vue';

const props = defineProps({
    player: { type: Object, required: true },
    certificateThresholds: { type: Object, default: () => ({}) },
});

const { t, locale } = useI18n();
const { can } = useCan();
const rtl = computed(() => locale.value === 'ar');

// Server sends years oldest first, each with its trimesters T1 → T3.
const years = computed(() => props.player.academic_years ?? []);
const newestFirst = computed(() => [...years.value].reverse());
const hasRecords = computed(() => years.value.some((y) => y.records?.length));

const fmt = (value) => (value === null || value === undefined ? '—' : Number(value).toFixed(2));
const passes = (grade, scale) => Number(grade) >= Number(scale) / 2;
const hasAverage = (y) => y.average !== null && y.average !== undefined;

// One cell per trimester: the year's record for it, or null.
const rows = computed(() => newestFirst.value.map((year) => ({
    year,
    cells: PERIODS.map((period) => ({ period, record: year.records?.find((r) => r.period === period) ?? null })),
})));

// Current school = the latest year's school info.
const currentSchool = computed(() => {
    const y = years.value.at(-1);
    if (!y) return '';
    return [y.education_level ? t(`education_level_${y.education_level}`) : null, y.institution, y.field_of_study]
        .filter(Boolean).join(' · ');
});

// Latest trimester: newest year that has grades, its last trimester.
const latest = computed(() => {
    const year = newestFirst.value.find((y) => y.records?.length);
    return year ? { record: year.records.at(-1), scale: year.scale } : null;
});

const averaged = computed(() => years.value.filter(hasAverage));
const currentAverage = computed(() => averaged.value.at(-1) ?? null);
const percent = (y) => (Number(y.average) / Number(y.scale)) * 100;
// Change between the last two year averages, compared in % of their scale, shown on /20.
const yearChange = computed(() => {
    const [prev, last] = averaged.value.slice(-2);
    if (!prev || !last) return null;
    const delta = ((percent(last) - percent(prev)) / 100) * 20;
    return Math.abs(delta) < 0.005 ? 0 : delta;
});

const chartData = computed(() => ({
    labels: averaged.value.map((y) => yearLabel(y.academic_year)),
    datasets: [
        { ...lineDataset(t('year_average'), averaged.value.map((y) => Number(percent(y).toFixed(1))), 0), pointRadius: 3 },
        {
            label: t('pass_mark'),
            data: averaged.value.map(() => 50),
            borderColor: mutedInk(),
            borderDash: [6, 4],
            borderWidth: 1,
            pointRadius: 0,
            pointHoverRadius: 0,
            fill: false,
        },
    ],
}));

const chartOptions = computed(() => {
    const options = baseOptions({ rtl: rtl.value });
    options.scales.y = {
        ...options.scales.y,
        min: 0,
        max: 100,
        ticks: { ...options.scales.y.ticks, stepSize: 25, callback: (v) => `${v}%` },
    };
    return options;
});

const CERTIFICATE_CLASSES = {
    excellence: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/15 dark:text-emerald-300',
    congratulations: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/15 dark:text-sky-300',
    encouragement: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/15 dark:text-amber-300',
    honor_roll: 'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-500/15 dark:text-violet-300',
};

// --- grade modal (add / edit / delete a trimester) ---
const gradeOpen = ref(false);
const gradePreset = ref({});
function openAdd(academicYear = null, period = null) {
    gradePreset.value = { academicYear, period };
    gradeOpen.value = true;
}
const canEdit = computed(() => can('players', 'edit'));
function openEdit(year, record) {
    if (!canEdit.value) return;
    gradePreset.value = { record: { ...record, academic_year: year.academic_year } };
    gradeOpen.value = true;
}

// --- school year: edit info / delete ---
const yearOpen = ref(false);
const editingYear = ref(null);
function openYear(year) {
    editingYear.value = year;
    yearOpen.value = true;
}

const removingYearId = ref(null);
const removingYear = ref(false);
function confirmRemoveYear() {
    removingYear.value = true;
    router.delete(route('players.academic-years.destroy', [props.player.id, removingYearId.value]), {
        preserveScroll: true,
        onSuccess: () => { removingYearId.value = null; },
        onFinish: () => { removingYear.value = false; },
    });
}

const th = 'px-3 py-3 text-xs font-semibold uppercase text-slate-500 dark:text-slate-400';
const gradeClass = (grade, scale) => (passes(grade, scale) ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400');
</script>

<template>
    <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 dark:border-slate-800 px-5 py-4">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('academic_progress') }}</h3>
            <div class="flex items-center gap-2">
                <a :href="route('players.academic-report', player.id)" target="_blank" class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800">
                    <Icon name="print" /> {{ t('print_academic_report') }}
                </a>
                <button v-if="can('players', 'add')" type="button" @click="openAdd()" class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-300 hover:bg-primary-50 dark:text-primary-300 dark:ring-primary-700 dark:hover:bg-primary-900/30">
                    <Icon name="plus" /> {{ t('add_gpa') }}
                </button>
            </div>
        </div>

        <div v-if="currentSchool || hasRecords" class="space-y-5 px-5 py-4">
            <p v-if="currentSchool" class="text-sm">
                <span class="text-slate-500 dark:text-slate-400">{{ t('current_school') }}:</span>
                <span class="ms-1 font-medium text-slate-900 dark:text-slate-100">{{ currentSchool }}</span>
            </p>

            <div v-if="hasRecords" class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('latest_gpa') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums" :class="passes(latest.record.gpa, latest.scale) ? 'text-emerald-600' : 'text-rose-600'">
                        <bdi dir="ltr">{{ fmt(latest.record.gpa) }}<span class="text-sm font-normal text-slate-400"> / {{ latest.scale }}</span></bdi>
                    </p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('year_average') }}</p>
                    <p class="mt-1 flex flex-wrap items-baseline gap-2 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100">
                        <template v-if="currentAverage">
                            <bdi dir="ltr">{{ fmt(currentAverage.average) }}<span class="text-sm font-normal text-slate-400"> / {{ currentAverage.scale }}</span></bdi>
                            <span v-if="currentAverage.is_provisional" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">{{ t('provisional') }}</span>
                        </template>
                        <template v-else>—</template>
                    </p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('year_change') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums"
                       :class="!yearChange ? 'text-slate-400' : yearChange > 0 ? 'text-emerald-600' : 'text-rose-600'">
                        <template v-if="yearChange === null">—</template>
                        <bdi v-else dir="ltr">{{ yearChange > 0 ? `▲ +${yearChange.toFixed(2)}` : yearChange < 0 ? `▼ ${Math.abs(yearChange).toFixed(2)}` : '0.00' }}<span class="text-sm font-normal text-slate-400"> / 20</span></bdi>
                    </p>
                </div>
            </div>

            <div v-if="averaged.length >= 2" class="h-64">
                <Line :data="chartData" :options="chartOptions" />
            </div>
        </div>

        <div v-if="!years.length" class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_gpa_records') }}</div>
        <div v-else class="overflow-x-auto border-t border-slate-100 dark:border-slate-800">
            <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                <thead class="bg-slate-50 dark:bg-slate-950">
                    <tr>
                        <th :class="[th, 'text-start']">{{ t('academic_year') }}</th>
                        <th :class="[th, 'text-start']">{{ t('education_level') }}</th>
                        <th :class="[th, 'text-start']">{{ t('institution') }}</th>
                        <th :class="[th, 'text-start']">{{ t('field_of_study') }}</th>
                        <th v-for="p in PERIODS" :key="p" :class="[th, 'text-center']">{{ t(`period_${p}`) }}</th>
                        <th :class="[th, 'text-center']">{{ t('year_average') }}</th>
                        <th class="px-3 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="{ year: y, cells } in rows" :key="y.id" class="align-top">
                        <td class="whitespace-nowrap px-3 py-3 text-sm font-medium tabular-nums text-slate-700 dark:text-slate-200"><bdi dir="ltr">{{ yearLabel(y.academic_year) }}</bdi></td>
                        <td class="whitespace-nowrap px-3 py-3 text-sm text-slate-700 dark:text-slate-200">{{ y.education_level ? t(`education_level_${y.education_level}`) : '—' }}</td>
                        <td class="px-3 py-3 text-sm text-slate-600 dark:text-slate-300">{{ y.institution || '—' }}</td>
                        <td class="px-3 py-3 text-sm text-slate-600 dark:text-slate-300">{{ y.field_of_study || '—' }}</td>
                        <td v-for="cell in cells" :key="cell.period" class="px-2 py-2 text-center">
                            <component :is="canEdit ? 'button' : 'div'" v-if="cell.record" :type="canEdit ? 'button' : undefined"
                                :title="cell.record.remark || undefined"
                                class="flex w-full min-w-[6.5rem] flex-col items-center gap-1 rounded-lg px-2 py-1"
                                :class="{ 'hover:bg-slate-50 dark:hover:bg-slate-800': canEdit }"
                                @click="openEdit(y, cell.record)">
                                <bdi dir="ltr" class="text-sm font-semibold tabular-nums" :class="gradeClass(cell.record.gpa, y.scale)">
                                    {{ fmt(cell.record.gpa) }}<span class="text-xs font-normal text-slate-400"> / {{ y.scale }}</span>
                                </bdi>
                                <span v-if="cell.record.certificate"
                                    class="rounded-full px-2 py-0.5 text-[10px] font-semibold leading-tight ring-1 ring-inset"
                                    :class="CERTIFICATE_CLASSES[cell.record.certificate]">{{ t(`certificate_${cell.record.certificate}`) }}</span>
                            </component>
                            <button v-else-if="can('players', 'add')" type="button" @click="openAdd(y.academic_year, cell.period)"
                                :aria-label="t('add_gpa')" :title="t('add_gpa')"
                                class="rounded-md border border-dashed border-slate-300 px-2 py-1 text-slate-400 hover:bg-slate-50 hover:text-primary-600 dark:border-slate-700 dark:hover:bg-slate-800">
                                <Icon name="plus" />
                            </button>
                            <span v-else class="text-sm text-slate-400">—</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-center">
                            <template v-if="hasAverage(y)">
                                <bdi dir="ltr" class="text-sm font-semibold tabular-nums" :class="gradeClass(y.average, y.scale)">
                                    {{ fmt(y.average) }}<span class="text-xs font-normal text-slate-400"> / {{ y.scale }}</span>
                                </bdi>
                                <span v-if="y.is_provisional" class="mt-1 block text-[10px] font-medium text-amber-600 dark:text-amber-400">{{ t('provisional') }}</span>
                            </template>
                            <span v-else class="text-sm text-slate-400">—</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-end">
                            <button v-if="can('players', 'edit')" type="button" @click="openYear(y)"
                                class="rounded-md px-2 py-1 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-300 hover:bg-primary-50 dark:text-primary-300 dark:ring-primary-700 dark:hover:bg-primary-900/30">{{ t('edit_year') }}</button>
                            <button v-if="can('players', 'delete')" type="button" @click="removingYearId = y.id"
                                class="ms-2 rounded-md px-2 py-1 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-300 hover:bg-rose-50 dark:text-rose-300 dark:ring-rose-800 dark:hover:bg-rose-900/30">{{ t('delete_year') }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <AcademicGradeModal
            :show="gradeOpen"
            :player="player"
            :years="years"
            :certificate-thresholds="certificateThresholds"
            :preset="gradePreset"
            @close="gradeOpen = false"
        />
        <AcademicYearModal :show="yearOpen" :player="player" :year="editingYear" @close="yearOpen = false" />
        <ConfirmModal
            :show="removingYearId !== null"
            :title="t('delete_year')"
            :message="t('delete_year_warning')"
            :busy="removingYear"
            @confirm="confirmRemoveYear"
            @cancel="removingYearId = null"
        />
    </div>
</template>
