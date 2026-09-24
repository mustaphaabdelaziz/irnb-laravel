<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { EDUCATION_LEVELS, yearLabel } from '@/lib/academic';

// School info of one school year: level, institution, class.
const props = defineProps({
    show: { type: Boolean, default: false },
    player: { type: Object, required: true },
    year: { type: Object, default: null },
});

const emit = defineEmits(['close']);
const { t } = useI18n();

const form = useForm({ education_level: '', institution: '', field_of_study: '' });

watch(() => props.show, (open) => {
    if (!open || !props.year) return;
    form.clearErrors();
    form.education_level = props.year.education_level ?? '';
    form.institution = props.year.institution ?? '';
    form.field_of_study = props.year.field_of_study ?? '';
});

function submit() {
    // Always send all three: an omitted field is kept as-is server-side.
    form.transform((data) => ({
        education_level: data.education_level,
        institution: data.institution || null,
        field_of_study: data.field_of_study || null,
    })).put(route('players.academic-years.update', [props.player.id, props.year.id]), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Modal :show="show" @close="emit('close')" max-width="md">
        <form v-if="year" @submit.prevent="submit" class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                {{ t('edit_year') }} <bdi dir="ltr" class="tabular-nums">{{ yearLabel(year.academic_year) }}</bdi>
            </h3>
            <InputError :message="form.errors.student" class="mt-2" />
            <div class="mt-4 grid gap-4">
                <div>
                    <InputLabel :value="t('education_level')" />
                    <select v-model="form.education_level" required class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
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
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="emit('close')">{{ t('cancel') }}</SecondaryButton>
                <PrimaryButton :disabled="form.processing">{{ t('save') }}</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
