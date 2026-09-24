<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan';
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { CERTIFICATES, EDUCATION_LEVELS, PERIODS, scaleForLevel, suggestCertificate, yearLabel } from '@/lib/academic';

// Add a trimester grade (optionally opening a new school year) or edit one.
// `preset` = { record } to edit, or { academicYear, period } to prefill an add.
const props = defineProps({
    show: { type: Boolean, default: false },
    player: { type: Object, required: true },
    years: { type: Array, default: () => [] },
    certificateThresholds: { type: Object, default: () => ({}) },
    preset: { type: Object, default: () => ({}) },
    // The club's current season start year, from the server.
    currentSchoolYear: { type: Number, default: null },
});

const emit = defineEmits(['close']);
const { t } = useI18n();
const { can } = useCan();

const defaultYear = () => props.currentSchoolYear
    ?? (new Date().getMonth() >= 8 ? new Date().getFullYear() : new Date().getFullYear() - 1);

// A blank grade form. Re-applied as the form's defaults on every open: after a
// successful submit Inertia makes the submitted data the new defaults.
const blank = () => ({
    academic_year: defaultYear(),
    period: 'T1',
    gpa: '',
    certificate: '',
    remark: '',
    education_level: '',
    institution: '',
    field_of_study: '',
});

const editingId = ref(null);
const form = useForm(blank());

const existingYear = computed(() => props.years.find((y) => Number(y.academic_year) === Number(form.academic_year)) ?? null);
const isNewYear = computed(() => !editingId.value && !existingYear.value);
const scale = computed(() => (existingYear.value ? Number(existingYear.value.scale) : scaleForLevel(form.education_level)));
// Adding: a trimester already graded in that year can't be picked again.
const filledPeriods = computed(() => (editingId.value ? [] : (existingYear.value?.records ?? []).map((r) => r.period)));

const yearOptions = computed(() => {
    const top = new Date().getFullYear();
    const years = new Set(Array.from({ length: 12 }, (_, i) => top - i));
    props.years.forEach((y) => years.add(Number(y.academic_year)));
    if (form.academic_year) years.add(Number(form.academic_year));
    return [...years].sort((a, b) => b - a);
});

// Suggest a certificate from the grade until the user picks one themselves.
const certificateTouched = ref(false);
function suggest() {
    if (!certificateTouched.value) form.certificate = suggestCertificate(form.gpa, scale.value, props.certificateThresholds);
}

const confirmingDelete = ref(false);
const deleting = ref(false);
const deleteError = ref('');

// Adding to a year whose three trimesters are all entered: nothing to add.
const yearComplete = computed(() => !editingId.value && filledPeriods.value.length >= PERIODS.length);

// The first trimester the year still lacks; keeps the current one when none is free.
function firstFreePeriod() {
    return PERIODS.find((p) => !filledPeriods.value.includes(p)) ?? form.period;
}

watch(() => props.show, (open) => {
    if (!open) return;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    certificateTouched.value = false;
    confirmingDelete.value = false;
    deleteError.value = '';
    const { record, academicYear, period } = props.preset ?? {};
    if (record) {
        editingId.value = record.id;
        form.academic_year = Number(record.academic_year ?? academicYear);
        form.period = record.period;
        form.gpa = record.gpa;
        form.certificate = record.certificate ?? '';
        form.remark = record.remark ?? '';
    } else {
        editingId.value = null;
        if (academicYear) form.academic_year = Number(academicYear);
        form.period = period ?? firstFreePeriod();
    }
});

// Switching year while adding: move off a trimester that year already has, and
// re-suggest the certificate since the year's scale may differ.
watch(() => form.academic_year, () => {
    if (!editingId.value && filledPeriods.value.includes(form.period)) form.period = firstFreePeriod();
    if (!editingId.value && form.gpa !== '') suggest();
});

function submit() {
    const newYear = isNewYear.value;
    form.transform((data) => {
        const base = {
            period: data.period,
            gpa: data.gpa,
            certificate: data.certificate || null,
            remark: data.remark || null,
        };
        if (editingId.value) return base;
        return {
            academic_year: data.academic_year,
            ...base,
            ...(newYear ? {
                education_level: data.education_level || null,
                institution: data.institution || null,
                field_of_study: data.field_of_study || null,
            } : {}),
        };
    });
    const options = { preserveScroll: true, onSuccess: () => emit('close') };
    if (editingId.value) {
        form.put(route('players.academic-records.update', [props.player.id, editingId.value]), options);
    } else {
        form.post(route('players.academic-records.store', props.player.id), options);
    }
}

function destroy() {
    deleting.value = true;
    deleteError.value = '';
    router.delete(route('players.academic-records.destroy', [props.player.id, editingId.value]), {
        preserveScroll: true,
        onSuccess: () => { confirmingDelete.value = false; emit('close'); },
        onError: (errors) => { deleteError.value = errors.student || Object.values(errors)[0] || t('save_failed'); },
        onFinish: () => { deleting.value = false; },
    });
}

const selectClass = 'mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500 disabled:opacity-60';
</script>

<template>
    <Modal :show="show" @close="emit('close')" max-width="lg">
        <form @submit.prevent="submit" class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ editingId ? t('edit_gpa') : t('add_gpa') }}</h3>
            <InputError :message="form.errors.student" class="mt-2" />
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel :value="t('academic_year')" />
                    <select v-model.number="form.academic_year" :disabled="!!editingId" :class="selectClass">
                        <option v-for="y in yearOptions" :key="y" :value="y">{{ yearLabel(y) }}</option>
                    </select>
                    <InputError :message="form.errors.academic_year" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('trimester')" />
                    <select v-model="form.period" :class="selectClass">
                        <option v-for="p in PERIODS" :key="p" :value="p" :disabled="filledPeriods.includes(p)">{{ t(`period_${p}`) }}</option>
                    </select>
                    <InputError :message="form.errors.period" class="mt-1" />
                    <p v-if="yearComplete" class="mt-1 text-xs text-amber-600 dark:text-amber-400">{{ t('academic_year_complete') }}</p>
                </div>

                <template v-if="isNewYear">
                    <p class="sm:col-span-2 rounded-lg bg-primary-50 px-3 py-2 text-xs text-primary-800 dark:bg-primary-900/30 dark:text-primary-200">{{ t('new_year_school_info') }}</p>
                    <div class="sm:col-span-2">
                        <InputLabel :value="t('education_level')" />
                        <select v-model="form.education_level" @change="suggest" required :class="selectClass">
                            <option value="" disabled>-</option>
                            <option v-for="level in EDUCATION_LEVELS" :key="level" :value="level">{{ t(`education_level_${level}`) }}</option>
                        </select>
                        <InputError :message="form.errors.education_level" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('institution')" />
                        <TextInput v-model="form.institution" type="text" maxlength="255" class="mt-1 w-full" />
                        <InputError :message="form.errors.institution" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('field_of_study')" />
                        <TextInput v-model="form.field_of_study" type="text" maxlength="255" class="mt-1 w-full" />
                        <InputError :message="form.errors.field_of_study" class="mt-1" />
                    </div>
                </template>

                <div>
                    <InputLabel>{{ t('gpa') }} <bdi dir="ltr">/ {{ scale }}</bdi></InputLabel>
                    <TextInput v-model="form.gpa" @input="suggest" type="number" step="0.01" min="0" :max="scale" class="mt-1 w-full" required />
                    <InputError :message="form.errors.gpa" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('certificate')" />
                    <select v-model="form.certificate" @change="certificateTouched = true" :class="selectClass">
                        <option value="">{{ t('no_certificate') }}</option>
                        <option v-for="c in CERTIFICATES" :key="c" :value="c">{{ t(`certificate_${c}`) }}</option>
                    </select>
                    <InputError :message="form.errors.certificate" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <InputLabel :value="t('remark')" />
                    <textarea v-model="form.remark" rows="2" maxlength="1000" :class="selectClass"></textarea>
                    <InputError :message="form.errors.remark" class="mt-1" />
                </div>
            </div>

            <InputError v-if="deleteError" :message="deleteError" class="mt-4" />
            <div v-if="confirmingDelete" class="mt-6 flex flex-wrap items-center justify-end gap-3 rounded-lg bg-rose-50 p-3 dark:bg-rose-900/20">
                <p class="me-auto text-sm text-rose-700 dark:text-rose-300">{{ t('delete_gpa_warning') }}</p>
                <SecondaryButton type="button" @click="confirmingDelete = false">{{ t('cancel') }}</SecondaryButton>
                <DangerButton type="button" :disabled="deleting" @click="destroy">{{ t('delete') }}</DangerButton>
            </div>
            <div v-else class="mt-6 flex flex-wrap items-center justify-end gap-3">
                <button v-if="editingId && can('players', 'delete')" type="button" @click="confirmingDelete = true"
                    class="me-auto rounded-md px-3 py-1.5 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-300 hover:bg-rose-50 dark:text-rose-300 dark:ring-rose-800 dark:hover:bg-rose-900/30">{{ t('delete') }}</button>
                <SecondaryButton type="button" @click="emit('close')">{{ t('cancel') }}</SecondaryButton>
                <PrimaryButton :disabled="form.processing || yearComplete">{{ t('save') }}</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
