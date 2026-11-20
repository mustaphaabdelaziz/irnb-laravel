<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { localToday } from '@/lib/localDate';

// One history line, typed by hand (registration given) or corrected (line given).
// The statuses are editable on a new or hand-typed line only.
const props = defineProps({
    show: { type: Boolean, default: false },
    playerId: { type: Number, required: true },
    registration: { type: Object, default: null },
    line: { type: Object, default: null },
    playerStatuses: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);
const { t } = useI18n();
const today = localToday();

const form = useForm({ from_status_id: '', to_status_id: '', changed_on: '', note: '' });
const statusesEditable = computed(() => !props.line || props.line.origin === 'manual');
const selectClass = 'mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500';

watch(() => props.show, (open) => {
    if (!open) return;
    form.clearErrors();
    form.from_status_id = props.line?.from_status_id ?? '';
    form.to_status_id = props.line?.to_status_id ?? '';
    form.changed_on = props.line?.changed_on ?? today;
    form.note = props.line?.note ?? '';
});

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };
    form.transform((data) => ({
        changed_on: data.changed_on,
        note: data.note || null,
        ...(statusesEditable.value ? { from_status_id: data.from_status_id || null, to_status_id: data.to_status_id || null } : {}),
    }));
    if (props.line) {
        form.put(route('players.status-changes.update', [props.playerId, props.line.id]), options);
    } else {
        form.post(route('players.status-changes.store', [props.playerId, props.registration.id]), options);
    }
}
</script>

<template>
    <Modal :show="show" @close="emit('close')" max-width="md">
        <form v-if="line || registration" @submit.prevent="submit" class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ line ? t('status_history.edit') : t('status_history.add') }}</h3>
            <div class="mt-4 grid gap-4">
                <template v-if="statusesEditable">
                    <div>
                        <InputLabel :value="t('status_history.from_status')" />
                        <select v-model="form.from_status_id" :class="selectClass">
                            <option value="">-</option>
                            <option v-for="status in playerStatuses" :key="status.id" :value="status.id">{{ status.localized_name }}</option>
                        </select>
                        <InputError :message="form.errors.from_status_id" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('status_history.to_status') + ' *'" />
                        <select v-model="form.to_status_id" required :class="selectClass">
                            <option value="" disabled>-</option>
                            <option v-for="status in playerStatuses" :key="status.id" :value="status.id">{{ status.localized_name }}</option>
                        </select>
                        <InputError :message="form.errors.to_status_id" class="mt-1" />
                    </div>
                </template>
                <div>
                    <InputLabel :value="t('status_change.date') + ' *'" />
                    <TextInput v-model="form.changed_on" type="date" class="mt-1 w-full" :max="today" required />
                    <InputError :message="form.errors.changed_on" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('status_change.note')" />
                    <TextInput v-model="form.note" class="mt-1 w-full" maxlength="255" :placeholder="t('status_change.note_hint')" />
                    <InputError :message="form.errors.note" class="mt-1" />
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="emit('close')">{{ t('cancel') }}</SecondaryButton>
                <PrimaryButton :disabled="form.processing">{{ t('save') }}</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
