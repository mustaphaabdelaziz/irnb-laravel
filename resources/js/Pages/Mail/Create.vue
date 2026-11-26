<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MailEntryForm from './Partials/MailEntryForm.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({ direction: String, correspondents: Object });
</script>

<template>
    <Head :title="t('mreg.new')" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('mreg.new') }}</h1></template>
        <MailEntryForm :direction="direction" :correspondents="correspondents" />
    </AuthenticatedLayout>
</template>
