<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IconButton from '@/Components/IconButton.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFormatDate } from '@/Composables/useFormatDate';
import { useStatusLabel } from '@/Composables/useStatusLabel';
import { localToday } from '@/lib/localDate';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();
const { formatDate } = useFormatDate();
const { statusLabel } = useStatusLabel();
const props = defineProps({ order: Object, prints: { type: Array, default: () => [] }, can: Object });

const STATUS_COLOR = { draft: 'slate', approved: 'amber', paid: 'emerald', cancelled: 'rose' };
const payOpen = ref(false);
const cancelOpen = ref(false);
const payForm = useForm({ payment_date: props.order.payment_date ? String(props.order.payment_date).slice(0, 10) : localToday() });
const cancelForm = useForm({ reason: '' });

const rows = computed(() => [
    { label: t('date'), value: formatDate(props.order.order_date) },
    { label: t('po.beneficiary'), value: props.order.beneficiary_label },
    { label: t('supplier.field.bank_name'), value: props.order.beneficiary_bank },
    { label: t('supplier.field.rib'), value: props.order.beneficiary_rib, ltr: true },
    { label: t('payment_method'), value: props.order.payment_method ? statusLabel('payment_method', props.order.payment_method) : null },
    { label: t('po.payment_reference'), value: props.order.payment_reference, ltr: true },
    { label: t('po.payment_date'), value: props.order.payment_date ? formatDate(props.order.payment_date) : null },
    { label: t('tx.invoice_number'), value: props.order.invoice_number, ltr: true },
    { label: t('tx.invoice_date'), value: props.order.invoice_date ? formatDate(props.order.invoice_date) : null },
    { label: t('category'), value: props.order.finance_category?.localized_name || props.order.finance_category?.name },
    { label: t('tx.board_meeting'), value: props.order.board_meeting ? [props.order.board_meeting.pv_number, props.order.board_meeting.title].filter(Boolean).join(' · ') : null },
    { label: t('po.observation'), value: props.order.observation },
    { label: t('po.cancel_reason'), value: props.order.cancel_reason },
].filter((row) => row.value));

const approve = () => router.post(route('payment-orders.approve', props.order.id), {}, { preserveScroll: true });
const pay = () => payForm.post(route('payment-orders.pay', props.order.id), { preserveScroll: true, onSuccess: () => { payOpen.value = false; } });
const cancel = () => cancelForm.post(route('payment-orders.cancel', props.order.id), { preserveScroll: true, onSuccess: () => { cancelOpen.value = false; } });
</script>

<template>
    <Head :title="t('po.title') + ' ' + order.reference" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-3">
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('po.title') }} <bdi dir="ltr">{{ order.reference }}</bdi></h1>
                    <Badge :label="statusLabel('payment_order', order.status)" :color="STATUS_COLOR[order.status]" />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <IconButton v-for="p in prints" :key="p" :href="route('payment-orders.print', { paymentOrder: order.id, form: p })" external target="_blank" icon="print" :label="t('po.print.' + p)" />
                    <IconButton v-if="can.edit" :href="route('payment-orders.edit', order.id)" icon="pencil" :label="t('edit')" />
                    <IconButton v-if="can.approve" icon="check" :label="t('perm_approve')" variant="primary" @click="approve" />
                    <IconButton v-if="can.pay" icon="money" :label="t('po.mark_paid')" variant="primary" @click="payOpen = true" />
                    <IconButton v-if="can.cancel" icon="xcircle" :label="t('po.cancel_order')" variant="danger" @click="cancelOpen = true" />
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('po.subject') }}</p>
                <h2 class="mt-1 text-lg font-bold text-slate-900 dark:text-slate-100">{{ order.subject }}</h2>
                <p class="mt-2 text-2xl font-bold tabular-nums text-rose-700 dark:text-rose-400"><bdi dir="ltr">{{ formatMoney(order.amount) }}</bdi></p>
                <dl class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div v-for="row in rows" :key="row.label">
                        <dt class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ row.label }}</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100"><bdi :dir="row.ltr ? 'ltr' : undefined">{{ row.value }}</bdi></dd>
                    </div>
                </dl>
                <p v-if="order.transaction" class="mt-5 text-sm">
                    {{ t('po.expense_link') }}
                    <Link :href="route('transactions.show', order.transaction.id)" class="font-semibold text-primary-600 hover:underline dark:text-primary-300"><bdi dir="ltr">#{{ order.transaction.id }}</bdi></Link>
                </p>
                <p v-if="order.status === 'draft'" class="mt-5 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                    {{ t('po.draft_hint') }}
                    <span v-if="!order.is_legacy && (!order.finance_category_id || !order.finance_account_id)" class="mt-1 block font-semibold">{{ t('po.approve_needs_finance') }}</span>
                </p>
            </div>
        </div>

        <Modal :show="payOpen" @close="payOpen = false" max-width="md">
            <form @submit.prevent="pay" class="space-y-4 p-6">
                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('po.mark_paid') }}</h3>
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('po.mark_paid_hint') }}</p>
                <TextInput v-model="payForm.payment_date" type="date" required class="w-full" />
                <InputError :message="payForm.errors.payment_date" />
                <div class="flex justify-end gap-2"><SecondaryButton type="button" @click="payOpen = false">{{ t('cancel') }}</SecondaryButton><PrimaryButton :disabled="payForm.processing">{{ t('po.mark_paid') }}</PrimaryButton></div>
            </form>
        </Modal>

        <Modal :show="cancelOpen" @close="cancelOpen = false" max-width="md">
            <form @submit.prevent="cancel" class="space-y-4 p-6">
                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('po.cancel_order') }}</h3>
                <TextInput v-model="cancelForm.reason" required maxlength="500" class="w-full" :placeholder="t('po.cancel_reason')" />
                <InputError :message="cancelForm.errors.reason" />
                <div class="flex justify-end gap-2"><SecondaryButton type="button" @click="cancelOpen = false">{{ t('back') }}</SecondaryButton><PrimaryButton :disabled="cancelForm.processing">{{ t('po.cancel_order') }}</PrimaryButton></div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
