<script setup>
import FitFigure from '@/Components/Stats/FitFigure.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import Icon from '@/Components/Icon.vue';
import IconButton from '@/Components/IconButton.vue';
import TransactionHistory from '@/Pages/Transactions/Partials/TransactionHistory.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCurrency } from '@/Composables/useCurrency';
import { useCan } from '@/Composables/useCan';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFormatDate } from '@/Composables/useFormatDate';
import { useFinanceAccountLabel } from '@/Composables/useFinanceAccountLabel';
import { useStatusLabel } from '@/Composables/useStatusLabel';
import { paymentStatusColor } from '@/lib/statusLabels';
import { actorLabel } from '@/lib/transactionActor';

const { t } = useI18n();
const { currencyLabel } = useCurrency();
const { statusLabel } = useStatusLabel();
const { accountLabel } = useFinanceAccountLabel();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    transaction: { type: Object, required: true },
    // The transaction's story, oldest first: recorded, edited, cancelled, printed.
    history: { type: Array, default: () => [] },
    // { recorded, modified }: who recorded it, who last changed it.
    trace: { type: Object, default: null },
    // Set for a cancelled or corrected transaction: { at, user, as_user, replaced_by }.
    cancellation: { type: Object, default: null },
    // The payments taken on the same receipt: { total, lines }.
    group: { type: Object, default: null },
    // { label, closed }
    season: { type: Object, default: null },
    // { id, reference } of its payment order, if one was made.
    paymentOrder: { type: Object, default: null },
});
const { can } = useCan();
const { formatDate, formatDateTime } = useFormatDate();
const isIncome = computed(() => props.transaction.transaction_type === 'income');
// The category's own name — never the stored slug.
const categoryName = computed(() => props.transaction.finance_category?.localized_name
    || props.transaction.finance_category?.name || t('tx.uncategorised'));
// Filled for a CCP or bank payment only; labelled as the form labels them.
const paymentDetails = computed(() => [
    { label: props.transaction.payment_method === 'ccp' ? t('ccp_number') : t('account_number'), value: props.transaction.payment_account },
    { label: t('ccp_key'), value: props.transaction.payment_ccp_key },
    { label: t('bank_name'), value: props.transaction.payment_bank_name },
    { label: t('holder'), value: props.transaction.payment_holder },
    { label: t('reference'), value: props.transaction.payment_reference },
    { label: t('tx.supplier'), value: props.transaction.supplier?.name },
    { label: t('tx.cheque_date'), value: props.transaction.cheque_date ? formatDate(props.transaction.cheque_date) : null },
    { label: t('tx.invoice_number'), value: props.transaction.invoice_number },
    { label: t('tx.invoice_date'), value: props.transaction.invoice_date ? formatDate(props.transaction.invoice_date) : null },
    { label: t('tx.board_meeting'), value: props.transaction.board_meeting ? [props.transaction.board_meeting.pv_number, props.transaction.board_meeting.title].filter(Boolean).join(' · ') : null },
].filter((row) => row.value));
</script>

<template>
    <Head :title="t('transaction')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2">
                    <Link :href="route('transactions.index')" :aria-label="t('back')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-300"><Icon name="back" class="rtl:rotate-180" /></Link>
                    <h1 class="min-w-0 truncate text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('transaction') }} #{{ transaction.id }}</h1>
                </div>
                <div class="flex items-center gap-2">
                    <IconButton :href="route('transactions.ticket', transaction.id)" external target="_blank" icon="print" :label="t('receipts.print_ticket')" variant="primary" data-help="tx-print-ticket" />
                    <IconButton :href="route('transactions.receipt', transaction.id)" external target="_blank" icon="document" :label="t('receipts.print_a4')" data-help="tx-print-a4" />
                    <IconButton v-if="paymentOrder && can('payment_orders', 'view')" :href="route('payment-orders.show', paymentOrder.id)" icon="document" :label="t('po.title') + ' ' + paymentOrder.reference" data-help="tx-payment-order" />
                    <IconButton v-else-if="!paymentOrder && transaction.transaction_type === 'expense' && !transaction.archived && can('payment_orders', 'add')" icon="plus" :label="t('po.create_from_expense')"
                        @click="router.post(route('transactions.payment-order.store', transaction.id))" data-help="tx-payment-order-create" />
                    <IconButton v-if="can('transactions', 'edit')" :href="route('transactions.edit', transaction.id)" icon="pencil" :label="t('edit')" />
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Cancelled, or corrected and replaced: said first, with who and when -->
            <div v-if="cancellation" data-help="tx-cancelled" class="rounded-2xl bg-rose-50 p-4 text-sm text-rose-800 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/30">
                <p class="font-semibold">{{ cancellation.replaced_by?.length ? t('tx_show.corrected_banner') : t('tx_show.cancelled_banner') }}</p>
                <p class="mt-1"><bdi>{{ actorLabel(t, cancellation) }}</bdi> · <bdi>{{ formatDateTime(cancellation.at) }}</bdi></p>
                <p v-if="cancellation.replaced_by?.length" class="mt-1">
                    {{ t('tx_show.corrected_by') }}
                    <Link v-for="id in cancellation.replaced_by" :key="id" :href="route('transactions.show', id)" class="ms-1 font-semibold underline" dir="ltr">#{{ id }}</Link>
                </p>
            </div>

            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="mb-5 border-b border-slate-100 pb-4 dark:border-slate-800">
                    <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('title') }}</p>
                    <h2 class="mt-1 text-lg font-bold text-slate-900 dark:text-slate-100">{{ transaction.display_title }}</h2>
                    <Link v-if="transaction.player_summary" :href="route('players.show', transaction.player_summary.id)" class="mt-1 inline-flex items-center gap-2 text-sm text-primary-600 hover:underline dark:text-primary-300">
                        {{ transaction.player_summary.name }}
                        <span class="font-mono text-xs text-slate-400">{{ transaction.player_summary.membership_id }}</span>
                    </Link>
                    <p v-if="transaction.replaces_transaction_id" class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                        {{ t('tx_show.corrects') }}
                        <Link :href="route('transactions.show', transaction.replaces_transaction_id)" class="ms-1 font-medium text-primary-600 hover:underline dark:text-primary-300" dir="ltr">#{{ transaction.replaces_transaction_id }}</Link>
                    </p>
                </div>
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div data-help="tx-show-amount" class="[container-type:inline-size]">
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('amount') }}</p>
                        <p class="mt-1 flex flex-wrap items-baseline gap-x-1 text-2xl font-bold tabular-nums [overflow-wrap:anywhere]" :class="isIncome ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'"><FitFigure dir="ltr" :value="(isIncome ? '+' : '−') + formatMoney(transaction.amount)" /><span class="text-sm font-medium text-slate-400">{{ currencyLabel }}</span></p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('type') }}</p>
                        <div class="mt-2"><Badge :label="isIncome ? t('income') : t('expense')" :color="isIncome ? 'emerald' : 'rose'" /></div>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('status') }}</p>
                        <div class="mt-2"><Badge :label="statusLabel('payment', transaction.status)" :color="paymentStatusColor(transaction.status)" /></div>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('category') }}</p>
                        <p class="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">{{ categoryName }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('date') }}</p>
                        <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ formatDate(transaction.transaction_date) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('payment_method') }}</p>
                        <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ statusLabel('payment_method', transaction.payment_method) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">
                            {{ isIncome ? t('destination_register') : t('source_register') }}
                        </p>
                        <p class="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">
                            {{ accountLabel(transaction.finance_account) }}
                        </p>
                    </div>
                    <div v-if="season">
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('season') }}</p>
                        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-900 dark:text-slate-100">
                            <span dir="ltr">{{ season.label }}</span>
                            <Badge v-if="season.closed" :label="t('closed')" color="slate" />
                        </p>
                    </div>
                    <div v-for="row in paymentDetails" :key="row.label">
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ row.label }}</p>
                        <!-- Isolated: an account number's digit groups keep their order in Arabic. -->
                        <p class="mt-1 text-sm text-slate-900 [overflow-wrap:anywhere] dark:text-slate-100"><bdi>{{ row.value }}</bdi></p>
                    </div>
                    <div v-if="transaction.receipt_url">
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('tx_show.attached_receipt') }}</p>
                        <a :href="transaction.receipt_url" target="_blank" rel="noopener" class="mt-1 inline-block text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">{{ t('view') }} <span aria-hidden="true">↗</span></a>
                    </div>
                    <div v-if="transaction.description" class="sm:col-span-2 lg:col-span-3">
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('description') }}</p>
                        <p class="mt-1 text-sm text-slate-700 dark:text-slate-200">{{ transaction.description }}</p>
                    </div>
                </div>

                <!-- Who recorded it and when; who last changed it -->
                <div v-if="trace?.recorded" data-help="tx-trace" class="mt-6 flex flex-wrap gap-x-8 gap-y-2 border-t border-slate-100 pt-4 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    <p>
                        {{ t('tx_trace.recorded_by') }}
                        <span class="font-medium text-slate-700 dark:text-slate-200"><bdi>{{ actorLabel(t, trace.recorded) }}</bdi> · <bdi>{{ formatDateTime(trace.recorded.at) }}</bdi></span>
                    </p>
                    <p v-if="trace.modified">
                        {{ t('tx_trace.modified_by') }}
                        <span class="font-medium text-slate-700 dark:text-slate-200"><bdi>{{ actorLabel(t, trace.modified) }}</bdi> · <bdi>{{ formatDateTime(trace.modified.at) }}</bdi></span>
                    </p>
                </div>
            </div>

            <!-- Related player subscriptions -->
            <div v-if="transaction.player_subscriptions?.length" class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h3 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('players') }}</h3>
                <div class="space-y-2">
                    <div v-for="ps in transaction.player_subscriptions" :key="ps.id" class="flex items-center justify-between rounded-lg bg-slate-50 dark:bg-slate-950 px-4 py-3">
                        <Link :href="route('players.show', ps.player_id)" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">
                            {{ ps.player?.fullname }}
                        </Link>
                        <span class="text-sm text-slate-600 dark:text-slate-300">{{ formatMoney(ps.amount_paid) }}</span>
                    </div>
                </div>
            </div>

            <!-- Paid on the same receipt: the other lines and the total -->
            <div v-if="group" data-help="tx-group" class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h3 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('tx_show.group_heading') }}</h3>
                <div class="space-y-2">
                    <div v-for="line in group.lines" :key="line.id" class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 dark:bg-slate-950 px-4 py-3">
                        <span v-if="line.current" class="flex min-w-0 flex-wrap items-center gap-2 text-sm font-medium text-slate-900 dark:text-slate-100">
                            <span dir="ltr">#{{ line.id }}</span> {{ line.title }}
                            <Badge :label="t('tx_show.this_one')" color="slate" />
                        </span>
                        <Link v-else :href="route('transactions.show', line.id)" class="min-w-0 text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">
                            <span dir="ltr">#{{ line.id }}</span> {{ line.title }}
                        </Link>
                        <span class="text-sm tabular-nums text-slate-600 dark:text-slate-300" dir="ltr">{{ formatMoney(line.amount) }}</span>
                    </div>
                </div>
                <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-3 text-sm font-semibold text-slate-900 dark:border-slate-800 dark:text-slate-100">
                    <span>{{ t('total') }}</span>
                    <span class="flex items-baseline gap-1 tabular-nums"><span dir="ltr">{{ formatMoney(group.total) }}</span><span class="text-xs font-medium text-slate-400">{{ currencyLabel }}</span></span>
                </div>
            </div>

            <!-- History: recorded, edited, cancelled, printed — who and when -->
            <div data-help="tx-prints" class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('tx_history.heading') }}</h3>
                    <Link :href="route('receipts.verify')" class="text-sm text-primary-600 hover:underline dark:text-primary-300">{{ t('receipts.verify_title') }}</Link>
                </div>
                <TransactionHistory :entries="history" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
