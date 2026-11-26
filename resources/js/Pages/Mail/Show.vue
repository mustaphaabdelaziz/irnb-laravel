<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormatDate } from '@/Composables/useFormatDate';

const { t } = useI18n();
const { formatDate } = useFormatDate();
const props = defineProps({ entry: Object, source: Object, can: Object });

const STATUS_COLOR = { active: 'emerald', cancelled: 'rose' };
const outgoing = computed(() => props.entry.direction === 'out');
const cancelOpen = ref(false);

const rows = computed(() => [
    { label: t('mreg.direction'), value: t(outgoing.value ? 'mreg.outgoing' : 'mreg.incoming') },
    { label: t('mreg.entry_date'), value: props.entry.entry_date ? formatDate(props.entry.entry_date) : null, ltr: true },
    { label: t(outgoing.value ? 'mreg.recipient' : 'mreg.sender'), value: props.entry.correspondent },
    { label: t('address'), value: props.entry.address },
    { label: t('mreg.subject'), value: props.entry.subject, wide: true },
    { label: t('mreg.sender_reference'), value: props.entry.sender_reference, ltr: true },
    { label: t('mreg.received_date'), value: props.entry.received_date ? formatDate(props.entry.received_date) : null, ltr: true },
    { label: t('notes'), value: props.entry.notes, wide: true, multiline: true },
].filter((row) => row.value));

const hint = computed(() => {
    if (props.entry.status === 'cancelled') return { text: t('mreg.cancelled_hint'), tone: 'rose' };
    if (props.entry.is_legacy) return { text: t('mreg.legacy_hint'), tone: 'amber' };
    if (props.entry.source_type) return { text: t('mreg.from_print_hint'), tone: 'amber' };
    return null;
});
const HINT_CLASS = {
    rose: 'bg-rose-50 text-rose-800 dark:bg-rose-500/10 dark:text-rose-200',
    amber: 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200',
};

// The dialog neither closes nor re-posts while the cancel is being saved.
const cancelling = ref(false);
const cancel = () => {
    if (cancelling.value) return;
    cancelling.value = true;
    router.post(route('mail.cancel', props.entry.id), {}, {
        preserveScroll: true, onFinish: () => { cancelling.value = false; cancelOpen.value = false; },
    });
};
</script>

<template>
    <Head :title="t('mreg.title') + ' ' + entry.reference" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('mreg.title') }} <bdi>{{ entry.reference }}</bdi></h1>
                    <Badge :label="t('mreg.status.' + entry.status)" :color="STATUS_COLOR[entry.status]" />
                    <Badge v-if="entry.is_legacy" :label="t('po.legacy')" color="slate" />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <IconButton v-if="can.print" :href="route('mail.print', entry.id)" external target="_blank" icon="print" :label="t('mreg.print_letter')" />
                    <IconButton v-if="entry.has_scan" :href="route('mail.scan', entry.id)" external target="_blank" icon="document" :label="t('mreg.scan_open')" />
                    <IconButton v-if="can.edit" :href="route('mail.edit', entry.id)" icon="pencil" :label="t('edit')" />
                    <IconButton v-if="can.cancel" icon="xcircle" :label="t('mreg.cancel_entry')" variant="danger" @click="cancelOpen = true" />
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-6">
            <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div v-for="row in rows" :key="row.label" :class="row.wide && 'sm:col-span-2'">
                        <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ row.label }}</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100" :class="row.multiline && 'whitespace-pre-line'"><bdi :dir="row.ltr ? 'ltr' : undefined">{{ row.value }}</bdi></dd>
                    </div>
                </dl>
                <p v-if="hint" class="mt-5 rounded-lg p-3 text-xs" :class="HINT_CLASS[hint.tone]">{{ hint.text }}</p>
            </div>

            <div v-if="source" class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('mreg.source_label') }}</h2>
                <p class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                    <span class="rounded bg-primary-50 px-1.5 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-500/15 dark:text-primary-300">{{ t('mreg.source.' + source.type) }}</span>
                    <Link v-if="source.url" :href="source.url" class="font-semibold text-primary-600 hover:underline dark:text-primary-300"><bdi>{{ source.label }}</bdi></Link>
                    <span v-else class="text-slate-600 dark:text-slate-300">{{ source.label }}</span>
                </p>
            </div>

            <div v-if="entry.body" class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('mreg.body') }}</h2>
                <p class="mt-3 whitespace-pre-line text-sm text-slate-800 dark:text-slate-200">{{ entry.body }}</p>
            </div>
        </div>

        <Modal :show="cancelOpen" :closeable="!cancelling" @close="cancelOpen = false" max-width="md">
            <div class="space-y-4 p-6">
                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('mreg.cancel_entry') }}</h3>
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('mreg.cancel_confirm') }}</p>
                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" :disabled="cancelling" @click="cancelOpen = false">{{ t('back') }}</SecondaryButton>
                    <DangerButton type="button" :disabled="cancelling" @click="cancel">{{ t('mreg.cancel_entry') }}</DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
