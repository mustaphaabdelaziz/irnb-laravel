<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    transaction: Object,
});

const typeColor = (type) => type === 'income' ? 'emerald' : 'rose';
const statusColor = (s) => s === 'Paid' ? 'emerald' : s === 'Partial' ? 'amber' : 'slate';
</script>

<template>
    <Head :title="t('transaction')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('transactions.index')" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </Link>
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('transaction') }} #{{ transaction.id }}</h1>
                </div>
                <div class="flex items-center gap-2">
                    <a :href="route('transactions.receipt', transaction.id)" target="_blank" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        🧾 {{ t('receipt') }}
                    </a>
                    <Link :href="route('transactions.edit', transaction.id)" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        {{ t('edit') }}
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('amount') }}</p>
                        <p class="mt-1 text-2xl font-bold" :class="transaction.transaction_type === 'income' ? 'text-emerald-700' : 'text-rose-700'">
                            {{ transaction.transaction_type === 'income' ? '+' : '-' }}{{ formatMoney(transaction.amount) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('type') }}</p>
                        <div class="mt-2"><Badge :label="transaction.transaction_type === 'income' ? t('income') : t('expense')" :color="typeColor(transaction.transaction_type)" /></div>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('status') }}</p>
                        <div class="mt-2"><Badge :label="transaction.status" :color="statusColor(transaction.status)" /></div>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('category') }}</p>
                        <p class="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">{{ transaction.category }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('date') }}</p>
                        <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ transaction.transaction_date }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('payment_method') }}</p>
                        <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ transaction.payment_method || '-' }}</p>
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
                            {{ ps.player?.firstname }} {{ ps.player?.lastname }}
                        </Link>
                        <span class="text-sm text-slate-600 dark:text-slate-300">{{ formatMoney(ps.amount_paid) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
