<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import MissionPrintButton from '@/Components/MissionPrintButton.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormatDate } from '@/Composables/useFormatDate';

const { t } = useI18n();
const { formatDate } = useFormatDate();
const props = defineProps({ order: Object, reference: String, can: Object });

const STATUS_COLOR = { active: 'emerald', cancelled: 'rose' };

// The reference a print on this page took, until the next reload. Once it exists, every button prints straight away.
const currentReference = ref(props.reference);
const registerUrl = computed(() => (props.can.register && !currentReference.value ? route('missions.register', props.order.id) : null));
const taken = (reference) => { currentReference.value = reference; };

const rows = computed(() => [
    { label: t('reference'), value: currentReference.value, ltr: true },
    { label: t('mis.order_date'), value: props.order.order_date ? formatDate(props.order.order_date) : null, ltr: true },
    { label: t('mis.destination'), value: props.order.destination },
    { label: t('mis.transport'), value: props.order.transport },
    { label: t('mis.departure_date'), value: props.order.departure_date ? formatDate(props.order.departure_date) : null, ltr: true },
    { label: t('mis.return_date'), value: props.order.return_date ? formatDate(props.order.return_date) : null, ltr: true },
    { label: t('mis.reason'), value: props.order.reason, wide: true, multiline: true },
].filter((row) => row.value));

const hint = computed(() => {
    if (props.order.status === 'cancelled') return { text: t('mis.cancelled_hint'), tone: 'rose' };
    if (props.order.is_legacy) return { text: t('mis.legacy_hint'), tone: 'amber' };
    if (!currentReference.value) return { text: t('mis.reference_hint'), tone: 'slate' };
    return null;
});
const HINT_CLASS = {
    rose: 'bg-rose-50 text-rose-800 dark:bg-rose-500/10 dark:text-rose-200',
    amber: 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200',
    slate: 'bg-slate-50 text-slate-700 dark:bg-slate-800/60 dark:text-slate-300',
};
const kindLabel = (type) => t('mis.kind.' + (type ?? 'typed'));

// The confirmation mounts on the first click; Modal opens from a watch on `show`, so show flips a tick later.
const cancelMounted = ref(false);
const cancelOpen = ref(false);
async function askCancel() {
    cancelMounted.value = true;
    await nextTick();
    cancelOpen.value = true;
}
// The dialog neither closes nor re-posts while the cancel is being saved.
const cancelling = ref(false);
const cancel = () => {
    if (cancelling.value) return;
    cancelling.value = true;
    router.post(route('missions.cancel', props.order.id), {}, {
        preserveScroll: true, onFinish: () => { cancelling.value = false; cancelOpen.value = false; },
    });
};
</script>

<template>
    <Head :title="t('mis.title') + (currentReference ? ' ' + currentReference : '')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('mis.title') }} <bdi v-if="currentReference">{{ currentReference }}</bdi></h1>
                    <Badge :label="t('mis.status.' + order.status)" :color="STATUS_COLOR[order.status]" />
                    <Badge v-if="order.is_legacy" :label="t('po.legacy')" color="slate" />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <MissionPrintButton :href="route('missions.print', order.id)" :register-url="registerUrl" :label="t('mis.print_all')" @registered="taken" />
                    <IconButton v-if="can.edit" :href="route('missions.edit', order.id)" icon="pencil" :label="t('edit')" />
                    <IconButton v-if="can.cancel" icon="xcircle" :label="t('mis.cancel_order')" variant="danger" @click="askCancel" />
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

            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="px-6 pt-5 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('mis.travelers') }}</h2>
                <table class="mt-3 min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3 text-start">#</th>
                            <th class="px-4 py-3 text-start">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('mis.function_label') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="(traveler, i) in order.travelers" :key="traveler.id">
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ i + 1 }}</td>
                            <td class="px-4 py-3 text-slate-900 dark:text-slate-100">
                                {{ traveler.name }}
                                <span class="ms-1 rounded bg-slate-100 px-1 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ kindLabel(traveler.traveler_type) }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ traveler.function || '—' }}</td>
                            <td class="px-4 py-3 text-end">
                                <MissionPrintButton :href="route('missions.print-traveler', [order.id, traveler.id])" :register-url="registerUrl"
                                    :label="t('mis.print_one')" size="sm" plain @registered="taken" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Modal v-if="cancelMounted" :show="cancelOpen" :closeable="!cancelling" max-width="md" @close="cancelOpen = false">
            <div class="space-y-4 p-6">
                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('mis.cancel_order') }}</h3>
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('mis.cancel_confirm') }}</p>
                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" :disabled="cancelling" @click="cancelOpen = false">{{ t('back') }}</SecondaryButton>
                    <DangerButton type="button" :disabled="cancelling" @click="cancel">{{ t('mis.cancel_order') }}</DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
