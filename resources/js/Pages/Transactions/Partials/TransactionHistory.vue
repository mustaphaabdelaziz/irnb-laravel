<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatDate } from '@/Composables/useFormatDate';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useStatusLabel } from '@/Composables/useStatusLabel';
import { actorLabel } from '@/lib/transactionActor';

defineProps({
    // TransactionHistory::entries — oldest first.
    entries: { type: Array, required: true },
});

const { t } = useI18n();
const { formatDate, formatDateTime } = useFormatDate();
const { formatMoney } = useFormatMoney();
const { statusLabel } = useStatusLabel();

const typeLabel = (type) => ({
    recorded: t('tx_history.type_recorded'),
    correction: t('tx_history.type_correction'),
    edited: t('tx_history.type_edited'),
    cancelled: t('tx_history.type_cancelled'),
    replaced: t('tx_history.type_replaced'),
    printed: t('tx_history.type_printed'),
}[type] ?? type);

const fieldLabel = (field) => ({
    title: t('title'),
    amount: t('amount'),
    transaction_date: t('date'),
    transaction_type: t('type'),
    finance_category_id: t('category'),
    finance_account_id: t('tx_history.field_register'),
    related_entity_id: t('receipts.field.player_id'),
    payment_method: t('payment_method'),
    payment_account: t('tx_history.field_account'),
    payment_ccp_key: t('ccp_key'),
    payment_bank_name: t('bank_name'),
    payment_holder: t('holder'),
    payment_reference: t('reference'),
    supplier_id: t('tx.supplier'),
    invoice_number: t('tx.invoice_number'),
    invoice_date: t('tx.invoice_date'),
    cheque_date: t('tx.cheque_date'),
    board_meeting_id: t('tx.board_meeting'),
    status: t('status'),
    description: t('description'),
    receipt: t('tx_show.attached_receipt'),
}[field] ?? field);

// A stored value as the page writes it everywhere else. Categories,
// registers and players arrive already named by the server.
const shown = (field, value) => {
    if (value === null || value === undefined || value === '') return '—';

    switch (field) {
        case 'amount': return formatMoney(value);
        case 'transaction_date':
        case 'invoice_date':
        case 'cheque_date': return formatDate(value);
        case 'transaction_type': return value === 'income' ? t('income') : t('expense');
        case 'status': return statusLabel('payment', value);
        case 'payment_method': return statusLabel('payment_method', value);
        default: return String(value);
    }
};

// Amounts keep their digit order in Arabic too; every other value is
// isolated (<bdi>) so an account number's digit groups stay in order.
const isNumber = (field) => field === 'amount';
</script>

<template>
    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
        <li v-for="(entry, index) in entries" :key="index" class="py-3 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-medium text-slate-900 dark:text-slate-100">
                    {{ typeLabel(entry.type) }}
                    <Link
                        v-for="target in entry.links"
                        :key="target.id"
                        :href="route('transactions.show', target.id)"
                        class="ms-1 text-primary-600 hover:underline dark:text-primary-300"
                        dir="ltr"
                    >#{{ target.id }}</Link>
                    <template v-if="entry.print">
                        <span class="ms-1">· {{ entry.print.copy_number === 1 ? t('receipts.original') : t('receipts.duplicate', { n: entry.print.copy_number }) }}</span>
                        <span class="ms-1 text-xs text-slate-500 dark:text-slate-400">{{ t(`receipts.format.${entry.print.format}`) }}</span>
                    </template>
                </span>
                <span class="text-slate-600 dark:text-slate-300"><bdi>{{ formatDateTime(entry.at) }}</bdi> · <bdi>{{ actorLabel(t, entry) }}</bdi></span>
                <span v-if="entry.print" class="font-mono text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ entry.print.code }}</span>
            </div>

            <div v-if="entry.changes?.length" class="mt-2 space-y-1 rounded-lg bg-slate-50 px-3 py-2 text-xs dark:bg-slate-950">
                <div v-for="change in entry.changes" :key="change.field" class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <span class="font-medium text-slate-500 dark:text-slate-400">{{ fieldLabel(change.field) }}</span>
                    <template v-if="change.field === 'receipt'">
                        <span class="text-slate-900 dark:text-slate-100">{{ t('tx_history.receipt_replaced') }}</span>
                    </template>
                    <template v-else>
                        <span class="text-slate-500 line-through [overflow-wrap:anywhere] dark:text-slate-400" :dir="isNumber(change.field) ? 'ltr' : null"><template v-if="isNumber(change.field)">{{ shown(change.field, change.old) }}</template><bdi v-else>{{ shown(change.field, change.old) }}</bdi></span>
                        <span aria-hidden="true" class="text-slate-400 rtl:rotate-180">→</span>
                        <span class="font-medium text-slate-900 [overflow-wrap:anywhere] dark:text-slate-100" :dir="isNumber(change.field) ? 'ltr' : null"><template v-if="isNumber(change.field)">{{ shown(change.field, change.new) }}</template><bdi v-else>{{ shown(change.field, change.new) }}</bdi></span>
                    </template>
                </div>
            </div>
            <p v-else-if="entry.type === 'edited'" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('tx_history.no_detail') }}</p>
        </li>
    </ul>
</template>
