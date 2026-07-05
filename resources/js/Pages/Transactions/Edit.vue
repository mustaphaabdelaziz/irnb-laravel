<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TransactionForm from '@/Pages/Transactions/Partials/TransactionForm.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({
    transaction: Object,
    financeCategories: { type: Array, default: () => [] },
    players: { type: Array, default: () => [] },
    clubCcp: { type: Object, default: null },
});
</script>

<template>
    <Head :title="t('edit')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('transactions.index')" class="text-slate-400 hover:text-slate-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('edit') }}</h1>
            </div>
        </template>
        <TransactionForm :transaction="transaction" :finance-categories="financeCategories" :players="players" :club-ccp="clubCcp" />
    </AuthenticatedLayout>
</template>
