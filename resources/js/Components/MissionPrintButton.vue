<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import IconButton from '@/Components/IconButton.vue';

/**
 * Print button of a mission order (every sheet, or one traveler's). With a
 * `registerUrl` — an active order typed here, with no reference yet, and the
 * missions edit right — the click takes the order's mail-register reference
 * (POST), then the PDF opens; the PDF route only looks the reference up, so
 * reprints keep it. Without one it is a plain link. No dialog: printing a
 * mission order always takes its reference (owner 2026-11-26), so there is
 * nothing to ask — and no Modal per table row.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    href: { type: String, required: true },
    // missions.register URL, or null: print straight away.
    registerUrl: { type: String, default: null },
    icon: { type: String, default: 'print' },
    label: { type: String, required: true },
    variant: { type: String, default: 'neutral' },
    size: { type: String, default: 'md' },
    plain: { type: Boolean, default: false },
});
const emit = defineEmits(['registered']);

const { t } = useI18n();
const busy = ref(false);
const failed = ref(false);

async function print() {
    if (busy.value) return;
    // Opened now, while the click still counts as the user's: a tab opened after the request is blocked as a pop-up.
    const tab = window.open('', '_blank');
    busy.value = true;
    failed.value = false;
    try {
        const { data } = await window.axios.post(props.registerUrl);
        emit('registered', data.reference);
        if (tab) tab.location.href = props.href;
        else window.open(props.href, '_blank');
    } catch {
        tab?.close();
        failed.value = true;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <IconButton v-if="!registerUrl" v-bind="$attrs" :href="href" external target="_blank" :icon="icon" :label="label" :variant="variant" :size="size" :plain="plain" />
    <span v-else class="inline-flex items-center gap-2">
        <IconButton v-bind="$attrs" :icon="icon" :label="label" :variant="variant" :size="size" :plain="plain" :disabled="busy" @click="print" />
        <span v-if="failed" role="alert" class="text-xs text-rose-600 dark:text-rose-300">{{ t('mis.register_failed') }}</span>
    </span>
</template>
