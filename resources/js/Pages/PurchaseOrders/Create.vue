<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PurchaseOrderForm from './Partials/PurchaseOrderForm.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({ suppliers: Array, catalogs: Array });
</script>

<template>
    <Head :title="t('pur.new')" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('pur.new') }}</h1></template>
        <PurchaseOrderForm :suppliers="suppliers" :catalogs="catalogs" />
    </AuthenticatedLayout>
</template>
