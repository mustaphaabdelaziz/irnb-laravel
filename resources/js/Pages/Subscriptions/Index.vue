<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { ref } from 'vue';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    subscriptions: Object,
});

const deleteId = ref(null);

function confirmDelete(id) {
    deleteId.value = id;
}

function destroy() {
    router.delete(route('subscriptions.destroy', deleteId.value), {
        onSuccess: () => { deleteId.value = null; },
    });
}
</script>

<template>
    <Head :title="t('subscriptions')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('subscriptions') }}</h1>
                <Link :href="route('subscriptions.create')" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 transition-colors">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    {{ t('add_subscription') }}
                </Link>
            </div>
        </template>

        <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('year') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('student_pricing') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('worker_pricing') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('categories') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="sub in subscriptions.data" :key="sub.id" class="hover:bg-slate-50 dark:hover:bg-slate-800">
                            <td class="px-4 py-3 text-sm font-medium text-slate-900 dark:text-slate-100">{{ sub.name }}</td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ sub.year }}</td>
                            <td class="px-4 py-3 text-end text-sm">{{ formatMoney(sub.amount_student) }}</td>
                            <td class="px-4 py-3 text-end text-sm">{{ formatMoney(sub.amount_worker) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <Badge v-for="cat in sub.categories" :key="cat.id" :label="cat.name" color="primary" />
                                    <span v-if="!sub.categories?.length" class="text-xs text-slate-400 dark:text-slate-500">{{ t('all') }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-end">
                                <div class="flex items-center justify-end gap-2">
                                    <Link :href="route('subscriptions.show', sub.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('details') }}</Link>
                                    <Link :href="route('subscriptions.edit', sub.id)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('edit') }}</Link>
                                    <button @click="confirmDelete(sub.id)" class="text-sm text-rose-500 hover:text-rose-700">{{ t('delete') }}</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!subscriptions.data?.length">
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="px-4">
                <Pagination :links="subscriptions" />
            </div>
        </div>

        <ConfirmModal
            :show="!!deleteId"
            :message="t('are_you_sure')"
            @confirm="destroy"
            @cancel="deleteId = null"
        />
    </AuthenticatedLayout>
</template>
