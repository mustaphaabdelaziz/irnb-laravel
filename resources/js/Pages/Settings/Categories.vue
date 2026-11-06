<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MemberCategoriesPanel from './Partials/MemberCategoriesPanel.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// Only a club with no branches lands here: otherwise /categories goes to a branch's tab.
defineProps({ categories: Array });
</script>

<template>
    <Head :title="t('categories')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('categories') }}</h1>
        </template>

        <div class="mx-auto max-w-2xl">
            <MemberCategoriesPanel :categories="categories" />
        </div>
    </AuthenticatedLayout>
</template>
