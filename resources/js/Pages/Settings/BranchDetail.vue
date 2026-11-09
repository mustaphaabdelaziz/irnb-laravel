<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BranchGeneralForm from './Partials/BranchGeneralForm.vue';
import MemberCategoriesPanel from './Partials/MemberCategoriesPanel.vue';
import { useCan } from '@/Composables/useCan';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({
    branch: { type: Object, required: true },
    tab: { type: String, required: true }, // general | categories
    categories: { type: Array, default: () => [] },
});

// Later releases add custom fields and documents here. Each tab has its own right.
const tabs = computed(() => [
    { key: 'general', label: t('branch_page.general'), href: route('branches.show', props.branch.id), shown: can('branches', 'view') },
    { key: 'categories', label: t('branch_page.categories'), href: route('categories.show', props.branch.id), shown: can('categories', 'view') },
].filter((tab) => tab.shown));

const name = computed(() => props.branch.localized_name || props.branch.name);
</script>

<template>
    <Head :title="name" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="flex items-center gap-2 text-xl font-bold text-slate-900 dark:text-slate-100">
                <img v-if="branch.logo_url" :src="branch.logo_url" alt="" class="h-7 w-7 rounded object-contain" />
                {{ name }}
            </h1>
        </template>

        <div class="mx-auto max-w-2xl space-y-6">
            <Link v-if="can('branches', 'view')" :href="route('branches.index')" class="inline-block text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">{{ t('branch_page.back') }}</Link>

            <div role="tablist" :aria-label="name" class="flex flex-wrap gap-0.5 rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800 sm:inline-flex">
                <Link
                    v-for="item in tabs"
                    :key="item.key"
                    :href="item.href"
                    role="tab"
                    :data-help="'branch-tab-' + item.key"
                    :aria-selected="tab === item.key"
                    class="rounded-md px-3 py-1 text-sm font-semibold"
                    :class="tab === item.key ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-slate-100' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300'"
                >{{ item.label }}</Link>
            </div>

            <BranchGeneralForm v-if="tab === 'general'" :branch="branch" />
            <MemberCategoriesPanel v-else :categories="categories" :branch-id="branch.id" />
        </div>
    </AuthenticatedLayout>
</template>
