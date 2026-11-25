<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFormatDate } from '@/Composables/useFormatDate';
import { useStatusLabel } from '@/Composables/useStatusLabel';
import { localToday } from '@/lib/localDate';

const { t } = useI18n();
const { can: userCan } = useCan();
const { formatMoney } = useFormatMoney();
const { formatDate } = useFormatDate();
const { statusLabel } = useStatusLabel();
const props = defineProps({ order: Object, can: Object });

const STATUS_COLOR = { draft: 'slate', sent: 'blue', received: 'emerald', cancelled: 'rose' };
const PAYMENT_COLOR = { draft: 'slate', approved: 'amber', paid: 'emerald', cancelled: 'rose' };
const receiveOpen = ref(false);
const cancelOpen = ref(false);
const receiveForm = useForm({ received_date: localToday(), to_stock: false, payment_order: false });

const rows = computed(() => [
    { label: t('date'), value: formatDate(props.order.order_date), ltr: true },
    { label: t('pur.supplier'), value: props.order.supplier?.name },
    { label: t('supplier.field.address'), value: props.order.supplier?.address },
    { label: t('supplier.field.phone'), value: props.order.supplier?.phone, ltr: true },
    { label: t('pur.requested_by'), value: props.order.requested_by },
    { label: t('pur.delivery_mode'), value: props.order.delivery_mode },
    { label: t('pur.payment_mode'), value: props.order.payment_mode ? statusLabel('payment_method', props.order.payment_mode) : null },
    { label: t('pur.received_date'), value: props.order.received_at ? formatDate(props.order.received_at) : null, ltr: true },
    { label: t('po.observation'), value: props.order.observation, multiline: true },
].filter((row) => row.value));

const send = () => router.post(route('purchase-orders.send', props.order.id), {}, { preserveScroll: true });
const receive = () => receiveForm.post(route('purchase-orders.receive', props.order.id), {
    preserveScroll: true, onSuccess: () => { receiveOpen.value = false; },
});
const cancel = () => router.post(route('purchase-orders.cancel', props.order.id), {}, {
    preserveScroll: true, onFinish: () => { cancelOpen.value = false; },
});
const creatingPaymentOrder = ref(false);
const createPaymentOrder = () => {
    if (creatingPaymentOrder.value) return;
    creatingPaymentOrder.value = true;
    router.post(route('purchase-orders.payment-order.store', props.order.id), {}, { onFinish: () => { creatingPaymentOrder.value = false; } });
};
</script>

<template>
    <Head :title="t('pur.title') + ' ' + order.reference" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('pur.title') }} <bdi dir="ltr">{{ order.reference }}</bdi></h1>
                    <Badge :label="statusLabel('purchase_order', order.status)" :color="STATUS_COLOR[order.status]" />
                    <Badge v-if="order.is_legacy" :label="t('po.legacy')" color="slate" />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <IconButton v-if="can.print" :href="route('purchase-orders.print', order.id)" external target="_blank" icon="print" :label="t('pur.print')" />
                    <IconButton v-if="can.edit" :href="route('purchase-orders.edit', order.id)" icon="pencil" :label="t('edit')" />
                    <IconButton v-if="can.send" icon="send" :label="t('pur.send')" variant="primary" @click="send" />
                    <IconButton v-if="can.receive" icon="check" :label="t('pur.receive')" variant="primary" @click="receiveOpen = true" />
                    <IconButton v-if="can.create_payment_order" :disabled="creatingPaymentOrder" icon="money" :label="t('pur.create_payment_order')" variant="primary" @click="createPaymentOrder" />
                    <IconButton v-if="can.cancel" icon="xcircle" :label="t('pur.cancel_order')" variant="danger" @click="cancelOpen = true" />
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-6">
            <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div v-for="row in rows" :key="row.label" :class="row.multiline && 'sm:col-span-2'">
                        <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ row.label }}</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100" :class="row.multiline && 'whitespace-pre-line'"><bdi :dir="row.ltr ? 'ltr' : undefined">{{ row.value }}</bdi></dd>
                    </div>
                </dl>
                <p v-if="order.status === 'draft'" class="mt-5 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ t('pur.draft_hint') }}</p>
                <p v-else-if="can.edit" class="mt-5 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ t('pur.prices_only_hint') }}</p>
            </div>

            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3 text-start">{{ t('designation') }}</th>
                            <th class="px-4 py-3 text-center">{{ t('quantity') }}</th>
                            <th class="px-4 py-3 text-end">{{ t('unit_price') }}</th>
                            <th class="px-4 py-3 text-end">{{ t('amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="line in order.lines" :key="line.id">
                            <td class="px-4 py-3 text-slate-900 dark:text-slate-100">
                                {{ line.designation }}
                                <span v-if="line.catalog" class="ms-1 rounded bg-primary-50 px-1 text-xs text-primary-700 dark:bg-primary-500/15 dark:text-primary-300">{{ line.catalog.name }}</span>
                            </td>
                            <td class="px-4 py-3 text-center tabular-nums"><bdi dir="ltr">{{ line.quantity }}</bdi></td>
                            <td class="px-4 py-3 text-end tabular-nums">
                                <bdi v-if="line.unit_price !== null" dir="ltr">{{ formatMoney(line.unit_price) }}</bdi>
                                <span v-else class="text-xs text-slate-400 dark:text-slate-500">{{ t('pur.unpriced') }}</span>
                            </td>
                            <td class="px-4 py-3 text-end tabular-nums"><bdi v-if="line.amount !== null" dir="ltr">{{ formatMoney(line.amount) }}</bdi></td>
                        </tr>
                    </tbody>
                    <tfoot v-if="order.fully_priced" class="bg-slate-50 text-sm font-semibold dark:bg-slate-950">
                        <tr><td colspan="3" class="px-4 py-3">{{ t('total') }}</td><td class="px-4 py-3 text-end tabular-nums"><bdi dir="ltr">{{ formatMoney(order.total) }}</bdi></td></tr>
                    </tfoot>
                </table>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                    <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('payment_orders') }}</h2>
                    <ul v-if="order.payment_orders.length" class="mt-3 space-y-2 text-sm">
                        <li v-for="p in order.payment_orders" :key="p.id" class="flex flex-wrap items-center gap-2">
                            <Link v-if="userCan('payment_orders', 'view')" :href="route('payment-orders.show', p.id)" class="font-semibold text-primary-600 hover:underline dark:text-primary-300"><bdi dir="ltr">{{ p.reference }}</bdi></Link>
                            <bdi v-else dir="ltr" class="font-semibold">{{ p.reference }}</bdi>
                            <span class="tabular-nums text-slate-600 dark:text-slate-300"><bdi dir="ltr">{{ formatMoney(p.amount) }}</bdi></span>
                            <Badge :label="statusLabel('payment_order', p.status)" :color="PAYMENT_COLOR[p.status]" />
                        </li>
                    </ul>
                    <p v-else class="mt-3 text-sm text-slate-500 dark:text-slate-400">—</p>
                    <p v-if="order.status === 'received' && !order.fully_priced && !order.is_legacy" class="mt-3 text-xs text-amber-700 dark:text-amber-300">{{ t('pur.needs_prices') }}</p>
                </div>
                <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                    <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('pur.equipment_units') }}</h2>
                    <p class="mt-3 text-2xl font-bold tabular-nums text-slate-900 dark:text-slate-100"><bdi dir="ltr">{{ order.equipment_units }}</bdi></p>
                </div>
            </div>
        </div>

        <Modal :show="receiveOpen" @close="receiveOpen = false" max-width="md">
            <form @submit.prevent="receive" class="space-y-4 p-6">
                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('pur.receive') }}</h3>
                <div>
                    <InputLabel :value="t('pur.received_date')" />
                    <TextInput v-model="receiveForm.received_date" type="date" required class="mt-1 w-full" />
                    <InputError :message="receiveForm.errors.received_date" class="mt-1" />
                </div>
                <label v-if="can.receive_stock" class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-200">
                    <Checkbox v-model:checked="receiveForm.to_stock" class="mt-0.5" />
                    <span>{{ t('pur.to_stock') }}<span class="block text-xs text-slate-500 dark:text-slate-400">{{ t('pur.to_stock_hint') }}</span></span>
                </label>
                <label v-if="can.payment_order_on_receive" class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-200">
                    <Checkbox v-model:checked="receiveForm.payment_order" class="mt-0.5" />
                    <span>{{ t('pur.with_payment_order') }}</span>
                </label>
                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" @click="receiveOpen = false">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="receiveForm.processing">{{ t('pur.receive') }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <Modal :show="cancelOpen" @close="cancelOpen = false" max-width="md">
            <div class="space-y-4 p-6">
                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('pur.cancel_order') }}</h3>
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('pur.cancel_confirm') }}</p>
                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" @click="cancelOpen = false">{{ t('back') }}</SecondaryButton>
                    <DangerButton type="button" @click="cancel">{{ t('pur.cancel_order') }}</DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
