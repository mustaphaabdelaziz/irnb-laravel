<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SubscriptionForm from './Partials/SubscriptionForm.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    subscription: Object,
    categories: Array,
    branches: { type: Array, default: () => [] },
    seasons: { type: Array, default: () => [] },
    currentSeason: { type: Number, required: true },
});
</script>

<template>
    <Head :title="t('edit')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('subscriptions.show', subscription.id)" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                    <svg class="h-5 w-5 rtl:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('edit') }} — {{ subscription.name }}</h1>
            </div>
        </template>

        <SubscriptionForm :subscription="subscription" :categories="categories" :branches="branches" :seasons="seasons" :current-season="currentSeason" :submit-label="t('save_changes')" />
    </AuthenticatedLayout>
</template>
