<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PaymentOrderForm from './Partials/PaymentOrderForm.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({
    financeCategories: Array, financeAccounts: Array, suppliers: Array,
    users: Array, boardMembers: Array, boardMeetings: Array, purchaseOrders: Array,
});
</script>

<template>
    <Head :title="t('po.new')" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('po.new') }}</h1></template>
        <PaymentOrderForm :finance-categories="financeCategories" :finance-accounts="financeAccounts" :suppliers="suppliers"
            :users="users" :board-members="boardMembers" :board-meetings="boardMeetings" :purchase-orders="purchaseOrders" />
    </AuthenticatedLayout>
</template>
