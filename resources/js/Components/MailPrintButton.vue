<script setup>
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import IconButton from '@/Components/IconButton.vue';
import Modal from '@/Components/Modal.vue';
import Checkbox from '@/Components/Checkbox.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useCan } from '@/Composables/useCan';

/**
 * A print button that can register the printed document in the mail register.
 * With a `source` and the mail add right it opens a small dialog: the
 * document's reference when it already has one, else the box «Register in
 * the mail register» (default `ticked`). Ticking posts to mail.sources.store,
 * then the PDF opens — the PDF route only looks the reference up, so opening
 * or reprinting never takes a number. Without a source or the right, it is
 * the plain print link it replaced.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    href: { type: String, required: true },
    // { type: 'absence-letter' | 'game' | 'purchase-order', id, query? } — query: the PDF's own (period, branch).
    source: { type: Object, default: null },
    ticked: { type: Boolean, default: false },
    icon: { type: String, default: 'print' },
    label: { type: String, required: true },
    variant: { type: String, default: 'neutral' },
    size: { type: String, default: 'md' },
    plain: { type: Boolean, default: false },
});

const { t } = useI18n();
const { can } = useCan();

const asks = computed(() => props.source !== null && can('mail', 'add'));
const open = ref(false);
const mounted = ref(false);
const loading = ref(false);
const busy = ref(false);
const entry = ref(null);
const register = ref(props.ticked);
const lookupFailed = ref(false);
const failed = ref(false);

const params = () => ({ type: props.source.type, id: props.source.id, ...(props.source.query ?? {}) });

async function ask() {
    if (loading.value) return;
    loading.value = true;
    failed.value = false;
    lookupFailed.value = false;
    try {
        const { data } = await window.axios.get(route('mail.sources.show', params()));
        entry.value = data.entry;
    } catch {
        // The register cannot be reached: the dialog still prints.
        entry.value = null;
        lookupFailed.value = true;
    }
    register.value = props.ticked;
    loading.value = false;
    // The dialog mounts on the first click (one Modal per row is DOM volume); Modal opens from a watch on `show`, so show flips a tick later.
    mounted.value = true;
    await nextTick();
    open.value = true;
}

async function print() {
    if (busy.value) return;
    if (entry.value || lookupFailed.value || !register.value) {
        window.open(props.href, '_blank');
        open.value = false;
        return;
    }
    // Opened now, while the click still counts as the user's: a tab opened after the request is blocked as a pop-up.
    const tab = window.open('', '_blank');
    busy.value = true;
    failed.value = false;
    try {
        const { data } = await window.axios.post(route('mail.sources.store', params()));
        entry.value = data.entry;
        if (tab) tab.location.href = props.href;
        else window.open(props.href, '_blank');
        open.value = false;
    } catch {
        tab?.close();
        failed.value = true;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <IconButton v-if="!asks" v-bind="$attrs" :href="href" external target="_blank" :icon="icon" :label="label" :variant="variant" :size="size" :plain="plain" />
    <template v-else>
        <IconButton v-bind="$attrs" :icon="icon" :label="label" :variant="variant" :size="size" :plain="plain" :disabled="loading" @click="ask" />
        <Modal v-if="mounted" :show="open" :closeable="!busy" max-width="sm" @close="open = false">
            <div class="space-y-4 p-6">
                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ label }}</h3>
                <p v-if="entry" class="text-sm text-slate-700 dark:text-slate-200">
                    {{ t('mreg.already_registered') }} <bdi class="font-semibold text-primary-700 dark:text-primary-300">{{ entry.reference }}</bdi>
                </p>
                <label v-else-if="!lookupFailed" class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-200">
                    <Checkbox v-model:checked="register" :disabled="busy" class="mt-0.5" />
                    <span>{{ t('mreg.register_tick') }}<span class="block text-xs text-slate-500 dark:text-slate-400">{{ t('mreg.register_hint') }}</span></span>
                </label>
                <p v-if="lookupFailed || failed" class="text-xs text-rose-600 dark:text-rose-300">{{ t('mreg.register_failed') }}</p>
                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" :disabled="busy" @click="open = false">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton type="button" :disabled="busy" @click="print">{{ t('print') }}</PrimaryButton>
                </div>
            </div>
        </Modal>
    </template>
</template>
