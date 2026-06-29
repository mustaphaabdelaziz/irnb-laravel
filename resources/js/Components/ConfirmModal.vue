<script setup>
import { useI18n } from 'vue-i18n';
import Modal from './Modal.vue';
import DangerButton from './DangerButton.vue';
import SecondaryButton from './SecondaryButton.vue';

const { t } = useI18n();

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: '' },
    message: { type: String, default: '' },
    confirmLabel: { type: String, default: '' },
});

const emit = defineEmits(['confirm', 'cancel']);
</script>

<template>
    <Modal :show="show" @close="emit('cancel')" max-width="md">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                {{ title || t('confirm_action') }}
            </h3>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
                {{ message || t('are_you_sure') }}
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="emit('cancel')">{{ t('cancel') }}</SecondaryButton>
                <DangerButton @click="emit('confirm')">{{ confirmLabel || t('delete') }}</DangerButton>
            </div>
        </div>
    </Modal>
</template>
