<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PaymentOrderForm from './Partials/PaymentOrderForm.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({
    order: Object,
    financeCategories: Array, financeAccounts: Array, suppliers: Array,
    users: Array, boardMembers: Array, boardMeetings: Array,
});
</script>

<template>
    <Head :title="t('po.title') + ' ' + order.reference" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('po.title') }} <bdi dir="ltr">{{ order.reference }}</bdi></h1></template>
        <PaymentOrderForm :order="order" :finance-categories="financeCategories" :finance-accounts="financeAccounts" :suppliers="suppliers"
            :users="users" :board-members="boardMembers" :board-meetings="boardMeetings" />
    </AuthenticatedLayout>
</template>
