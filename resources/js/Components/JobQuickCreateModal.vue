<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

/**
 * Create a job from inside the player form. It posts straight to the JSON
 * endpoint rather than making an Inertia visit, because a visit would throw
 * away everything already typed into the half-filled member form.
 */
const props = defineProps({ show: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'created']);
const { t } = useI18n();

const form = ref({ name_fr: '', name_ar: '', name_en: '' });
const error = ref('');
const similar = ref([]);
const saving = ref(false);

watch(() => props.show, (open) => {
    if (open) {
        form.value = { name_fr: '', name_ar: '', name_en: '' };
        error.value = '';
        similar.value = [];
    }
});

async function submit() {
    // The base `name` is the fallback HasLocalizedName reads, so it takes
    // whichever language was filled in first.
    const name = form.value.name_fr || form.value.name_ar || form.value.name_en;

    if (!name) {
        error.value = t('required');
        return;
    }

    saving.value = true;
    error.value = '';
    similar.value = [];

    try {
        // window.axios (resources/js/bootstrap.js) already sends the
        // X-XSRF-TOKEN header from the XSRF-TOKEN cookie and marks every
        // request as XMLHttpRequest, so Laravel answers with JSON here
        // instead of redirecting like a normal Inertia form post would.
        const response = await window.axios.post(route('jobs.quick.store'), { name, ...form.value });

        similar.value = response.data.similar ?? [];
        emit('created', response.data.job, similar.value.length
            ? t('job_similar_warning', { names: similar.value.map((j) => j.localized_name || j.name).join(', ') })
            : '');
        emit('close');
    } catch (e) {
        const status = e.response?.status;

        if (status === 409) {
            // Already there under another spelling: take it rather than add a twin.
            emit('created', e.response.data?.duplicate, t('job_exists'));
            emit('close');
        } else if (status === 422) {
            const errors = e.response.data?.errors;
            error.value = (errors && Object.values(errors)[0]?.[0]) || e.response.data?.message || t('save_failed');
        } else {
            // 419 (expired session) and anything else unexpected.
            error.value = t('save_failed');
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('new_job') }}</h3>
            <div class="mt-4 space-y-3">
                <div>
                    <InputLabel :value="t('job_name_fr')" />
                    <TextInput v-model="form.name_fr" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="t('job_name_ar')" />
                    <TextInput v-model="form.name_ar" class="mt-1 w-full" dir="rtl" />
                </div>
                <div>
                    <InputLabel :value="t('job_name_en')" />
                    <TextInput v-model="form.name_en" class="mt-1 w-full" />
                </div>
                <InputError :message="error" />
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="emit('close')">{{ t('cancel') }}</SecondaryButton>
                <PrimaryButton type="button" :disabled="saving" @click="submit">{{ t('save') }}</PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
