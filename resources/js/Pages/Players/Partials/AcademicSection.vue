<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Line } from 'vue-chartjs';
import { useI18n } from 'vue-i18n';
import '@/lib/registerCharts';
import { baseOptions, lineDataset, mutedInk } from '@/lib/chartTheme';
import { useCan } from '@/Composables/useCan';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PlayerFieldRow from '@/Components/PlayerFieldRow.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    player: { type: Object, required: true },
});

const PASS_MARK = 10;
const PERIODS = ['S1', 'S2', 'T1', 'T2', 'T3', 'ANNUAL'];

const { t, locale } = useI18n();
const { can } = useCan();
const rtl = computed(() => locale.value === 'ar');

// Server sends them oldest first (year, then period rank with ANNUAL last).
const records = computed(() => props.player.academic_records ?? []);
const gpa = (r) => Number(r.gpa);
const yearLabel = (year) => `${year}/${Number(year) + 1}`;
const passed = (r) => gpa(r) >= PASS_MARK;

const latest = computed(() => records.value.at(-1) ?? null);
const previous = computed(() => records.value.at(-2) ?? null);
const average = computed(() => (records.value.length
    ? records.value.reduce((sum, r) => sum + gpa(r), 0) / records.value.length
    : null));
const delta = computed(() => (latest.value && previous.value ? gpa(latest.value) - gpa(previous.value) : null));
const fmt = (value) => (value === null ? '—' : Number(value).toFixed(2));

// Newest first in the table; the chart reads left-to-right in time.
const tableRows = computed(() => [...records.value].reverse());

const chartData = computed(() => ({
    labels: records.value.map((r) => `${yearLabel(r.academic_year)} ${t(`period_${r.period}`)}`),
    datasets: [
        { ...lineDataset(t('gpa'), records.value.map(gpa), 0), pointRadius: 3 },
        {
            label: t('pass_mark'),
            data: records.value.map(() => PASS_MARK),
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
    options.scales.y = { ...options.scales.y, min: 0, max: 20, ticks: { ...options.scales.y.ticks, stepSize: 5 } };
    return options;
});

// --- add / edit ---
const showForm = ref(false);
const editingId = ref(null);
const form = useForm({
    academic_year: new Date().getMonth() >= 8 ? new Date().getFullYear() : new Date().getFullYear() - 1,
    period: 'S1',
    gpa: '',
    remark: '',
});

const yearOptions = computed(() => {
    const top = new Date().getFullYear();
    const years = Array.from({ length: 12 }, (_, i) => top - i);
    if (form.academic_year && !years.includes(Number(form.academic_year))) years.push(Number(form.academic_year));
    return years.sort((a, b) => b - a);
});

function openAdd() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
}

function openEdit(record) {
    editingId.value = record.id;
    form.academic_year = record.academic_year;
    form.period = record.period;
    form.gpa = record.gpa;
    form.remark = record.remark ?? '';
    form.clearErrors();
    showForm.value = true;
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => { showForm.value = false; form.reset(); editingId.value = null; },
    };
    form.transform((data) => ({ ...data, remark: data.remark || null }));
    if (editingId.value) {
        form.put(route('players.academic-records.update', [props.player.id, editingId.value]), options);
    } else {
        form.post(route('players.academic-records.store', props.player.id), options);
    }
}

// --- delete ---
const removingId = ref(null);

function confirmRemove() {
    router.delete(route('players.academic-records.destroy', [props.player.id, removingId.value]), {
        preserveScroll: true,
        onSuccess: () => { removingId.value = null; },
    });
}
</script>

<template>
    <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 dark:border-slate-800 px-5 py-4">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('academic_progress') }}</h3>
            <div class="flex items-center gap-2">
                <a :href="route('players.academic-report', player.id)" target="_blank" class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800">
                    <Icon name="print" /> {{ t('print_academic_report') }}
                </a>
                <button v-if="can('players', 'add')" type="button" @click="openAdd" class="rounded-md px-3 py-1.5 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-300 hover:bg-primary-50 dark:text-primary-300 dark:ring-primary-700 dark:hover:bg-primary-900/30">
                    {{ t('add_gpa') }}
                </button>
            </div>
        </div>

        <div class="space-y-5 px-5 py-4">
            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-3">
                <PlayerFieldRow icon="clipboard" :label="t('education_level')" :value="player.education_level ? t(`education_level_${player.education_level}`) : null" />
                <PlayerFieldRow icon="home" :label="t('institution')" :value="player.institution" />
                <PlayerFieldRow icon="document" :label="t('field_of_study')" :value="player.field_of_study" />
            </dl>

            <div v-if="records.length" class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('latest_gpa') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums" :class="passed(latest) ? 'text-emerald-600' : 'text-rose-600'"><bdi dir="ltr">{{ fmt(latest.gpa) }}<span class="text-sm font-normal text-slate-400"> / 20</span></bdi></p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('average_gpa') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100"><bdi dir="ltr">{{ fmt(average) }}<span class="text-sm font-normal text-slate-400"> / 20</span></bdi></p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('gpa_change') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums"
                       :class="delta === null || delta === 0 ? 'text-slate-400' : delta > 0 ? 'text-emerald-600' : 'text-rose-600'">
                        <template v-if="delta === null">—</template>
                        <template v-else>{{ delta > 0 ? `▲ +${delta.toFixed(2)}` : delta < 0 ? `▼ ${Math.abs(delta).toFixed(2)}` : '0.00' }}</template>
                    </p>
                </div>
            </div>

            <div v-if="records.length >= 2" class="h-64">
                <Line :data="chartData" :options="chartOptions" />
            </div>
        </div>

        <div v-if="!records.length" class="border-t border-slate-100 px-5 py-8 text-center text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">{{ t('no_gpa_records') }}</div>
        <div v-else class="overflow-x-auto border-t border-slate-100 dark:border-slate-800">
            <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                <thead class="bg-slate-50 dark:bg-slate-950">
                    <tr>
                        <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('academic_year') }}</th>
                        <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('period') }}</th>
                        <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('gpa') }}</th>
                        <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('status') }}</th>
                        <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('remark') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="r in tableRows" :key="r.id">
                        <td class="px-4 py-3 text-sm tabular-nums text-slate-600 dark:text-slate-300"><bdi dir="ltr">{{ yearLabel(r.academic_year) }}</bdi></td>
                        <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{{ t(`period_${r.period}`) }}</td>
                        <td class="px-4 py-3 text-end text-sm font-semibold tabular-nums" :class="passed(r) ? 'text-emerald-700' : 'text-rose-700'">{{ fmt(r.gpa) }}</td>
                        <td class="px-4 py-3"><Badge :label="t(passed(r) ? 'gpa_pass' : 'gpa_fail')" :color="passed(r) ? 'emerald' : 'rose'" /></td>
                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ r.remark || '—' }}</td>
                        <td class="px-4 py-3 text-end whitespace-nowrap">
                            <button v-if="can('players', 'edit')" type="button" @click="openEdit(r)" class="rounded-md px-2 py-1 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-300 hover:bg-primary-50 dark:text-primary-300 dark:ring-primary-700 dark:hover:bg-primary-900/30">{{ t('edit') }}</button>
                            <button v-if="can('players', 'delete')" type="button" @click="removingId = r.id" class="ms-2 rounded-md px-2 py-1 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-300 hover:bg-rose-50 dark:text-rose-300 dark:ring-rose-800 dark:hover:bg-rose-900/30">{{ t('remove') }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :show="showForm" @close="showForm = false" max-width="md">
            <form @submit.prevent="submit" class="p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ editingId ? t('edit_gpa') : t('add_gpa') }}</h3>
                <InputError :message="form.errors.student" class="mt-2" />
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel :value="t('academic_year')" />
                        <select v-model.number="form.academic_year" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option v-for="y in yearOptions" :key="y" :value="y">{{ yearLabel(y) }}</option>
                        </select>
                        <InputError :message="form.errors.academic_year" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('period')" />
                        <select v-model="form.period" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option v-for="p in PERIODS" :key="p" :value="p">{{ t(`period_${p}`) }}</option>
                        </select>
                        <InputError :message="form.errors.period" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel>{{ t('gpa') }} <bdi dir="ltr">/ 20</bdi></InputLabel>
                        <TextInput v-model="form.gpa" type="number" step="0.01" min="0" max="20" class="mt-1 w-full" required />
                        <InputError :message="form.errors.gpa" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel :value="t('remark')" />
                        <textarea v-model="form.remark" rows="2" maxlength="1000" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500"></textarea>
                        <InputError :message="form.errors.remark" class="mt-1" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" @click="showForm = false">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">{{ t('save') }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal
            :show="removingId !== null"
            :title="t('delete')"
            :message="t('delete_gpa_warning')"
            @confirm="confirmRemove"
            @cancel="removingId = null"
        />
    </div>
</template>
