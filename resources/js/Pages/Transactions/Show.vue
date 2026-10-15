<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import Icon from '@/Components/Icon.vue';
import IconButton from '@/Components/IconButton.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useFormatDate } from '@/Composables/useFormatDate';
import { useFinanceAccountLabel } from '@/Composables/useFinanceAccountLabel';
import { useStatusLabel } from '@/Composables/useStatusLabel';
import { paymentStatusColor } from '@/lib/statusLabels';

const { t } = useI18n();
const { statusLabel } = useStatusLabel();
const { accountLabel } = useFinanceAccountLabel();
const { formatMoney } = useFormatMoney();

const props = defineProps({ transaction: { type: Object, required: true } });
const { can } = useCan();
const { formatDate } = useFormatDate();
const isIncome = computed(() => props.transaction.transaction_type === 'income');
// The category's own name — never the stored slug.
const categoryName = computed(() => props.transaction.finance_category?.localized_name
    || props.transaction.finance_category?.name || t('tx.uncategorised'));
</script>

<template>
    <Head :title="t('transaction')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2">
                    <Link :href="route('transactions.index')" :aria-label="t('back')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:text-slate-500 dark:hover:bg-slate-800 dark:hover:text-slate-300"><Icon name="back" class="rtl:rotate-180" /></Link>
                    <h1 class="min-w-0 truncate text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('transaction') }} #{{ transaction.id }}</h1>
                </div>
                <div class="flex items-center gap-2">
                    <IconButton :href="route('transactions.receipt', transaction.id)" external target="_blank" icon="print" :label="t('receipt')" />
                    <IconButton v-if="can('transactions', 'edit')" :href="route('transactions.edit', transaction.id)" icon="pencil" :label="t('edit')" />
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="mb-5 border-b border-slate-100 pb-4 dark:border-slate-800">
                    <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('title') }}</p>
                    <h2 class="mt-1 text-lg font-bold text-slate-900 dark:text-slate-100">{{ transaction.display_title }}</h2>
                    <Link v-if="transaction.player_summary" :href="route('players.show', transaction.player_summary.id)" class="mt-1 inline-flex items-center gap-2 text-sm text-primary-600 hover:underline">
                        {{ transaction.player_summary.name }}
                        <span class="font-mono text-xs text-slate-400">{{ transaction.player_summary.membership_id }}</span>
                    </Link>
                </div>
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('amount') }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums [overflow-wrap:anywhere]" :class="isIncome ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'"><span dir="ltr">{{ isIncome ? '+' : '−' }}{{ formatMoney(transaction.amount) }}</span> <span class="text-sm font-medium text-slate-400">{{ t('currency_symbol') }}</span></p>
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
                    <div v-if="transaction.description" class="sm:col-span-2 lg:col-span-3">
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('description') }}</p>
                        <p class="mt-1 text-sm text-slate-700 dark:text-slate-200">{{ transaction.description }}</p>
                    </div>
                </div>
            </div>

            <!-- Related player subscriptions -->
            <div v-if="transaction.player_subscriptions?.length" class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h3 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('players') }}</h3>
                <div class="space-y-2">
                    <div v-for="ps in transaction.player_subscriptions" :key="ps.id" class="flex items-center justify-between rounded-lg bg-slate-50 dark:bg-slate-950 px-4 py-3">
                        <Link :href="route('players.show', ps.player_id)" class="text-sm font-medium text-primary-600 hover:underline">
                            {{ ps.player?.fullname }}
                        </Link>
                        <span class="text-sm text-slate-600 dark:text-slate-300">{{ formatMoney(ps.amount_paid) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
