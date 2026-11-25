<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PurchaseOrderForm from './Partials/PurchaseOrderForm.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({ order: Object, pricesOnly: Boolean, suppliers: Array, catalogs: Array });
</script>

<template>
    <Head :title="t('pur.title') + ' ' + order.reference" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('pur.title') }} <bdi dir="ltr">{{ order.reference }}</bdi></h1></template>
        <PurchaseOrderForm :order="order" :prices-only="pricesOnly" :suppliers="suppliers" :catalogs="catalogs" />
    </AuthenticatedLayout>
</template>
