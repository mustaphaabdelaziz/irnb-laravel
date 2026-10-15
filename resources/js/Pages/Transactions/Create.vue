<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TransactionForm from '@/Pages/Transactions/Partials/TransactionForm.vue';
import Icon from '@/Components/Icon.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({
    financeCategories: { type: Array, default: () => [] },
    financeAccounts: { type: Array, default: () => [] },
    players: { type: Array, default: () => [] },
    clubCcp: { type: Object, default: null },
    defaultFinanceAccountId: { type: Number, default: null },
    lockedAccount: { type: Object, default: null },
});
</script>

<template>
    <Head :title="t('add_transaction')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('transactions.index')" :aria-label="t('back')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:text-slate-500 dark:hover:bg-slate-800 dark:hover:text-slate-300"><Icon name="back" class="rtl:rotate-180" /></Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('add_transaction') }}</h1>
            </div>
        </template>
        <TransactionForm :finance-categories="financeCategories" :finance-accounts="financeAccounts" :players="players" :club-ccp="clubCcp" :default-finance-account-id="defaultFinanceAccountId" :locked-account="lockedAccount" />
    </AuthenticatedLayout>
</template>
