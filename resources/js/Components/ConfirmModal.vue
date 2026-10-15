<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Modal from './Modal.vue';
import DangerButton from './DangerButton.vue';
import PrimaryButton from './PrimaryButton.vue';
import SecondaryButton from './SecondaryButton.vue';

const { t } = useI18n();

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: '' },
    message: { type: String, default: '' },
    confirmLabel: { type: String, default: '' },
    // e.g. "Keep meeting" — a plain "Cancel" next to "Cancel meeting" misleads.
    cancelLabel: { type: String, default: '' },
    // Disables the confirm button while the request runs.
    busy: { type: Boolean, default: false },
    // 'danger' (default) for what destroys something; 'primary' for a confirm
    // that loses nothing, e.g. closing or reopening a season.
    variant: { type: String, default: 'danger' },
});

const emit = defineEmits(['confirm', 'cancel']);

// Pages clear the state behind title/message the moment the dialog is
// answered, while it is still fading out. Show the last copy given while
// open, so the closing box never goes blank.
const copy = ref({});
watch(
    () => [props.show, props.title, props.message, props.confirmLabel, props.cancelLabel, props.variant],
    () => {
        if (!props.show) return;
        copy.value = {
            title: props.title,
            message: props.message,
            confirmLabel: props.confirmLabel,
            cancelLabel: props.cancelLabel,
            variant: props.variant,
        };
    },
    { immediate: true },
);
</script>

<template>
    <Modal :show="show" @close="emit('cancel')" max-width="md">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                {{ copy.title || t('confirm_action') }}
            </h3>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
                {{ copy.message || t('are_you_sure') }}
            </p>
            <!-- Optional extra fields, e.g. a required reason. -->
            <slot />
            <div class="mt-6 flex flex-wrap justify-end gap-3">
                <SecondaryButton type="button" @click="emit('cancel')">{{ copy.cancelLabel || t('cancel') }}</SecondaryButton>
                <PrimaryButton v-if="copy.variant === 'primary'" type="button" :disabled="busy" @click="emit('confirm')">{{ copy.confirmLabel || t('confirm') }}</PrimaryButton>
                <DangerButton v-else type="button" :disabled="busy" @click="emit('confirm')">{{ copy.confirmLabel || t('delete') }}</DangerButton>
            </div>
        </div>
    </Modal>
</template>
