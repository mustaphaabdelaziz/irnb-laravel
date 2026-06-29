<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { ref, watch } from 'vue';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    transactions: Object,
    filters: Object,
});

const search = ref(props.filters?.search || '');
const typeFilter = ref(props.filters?.type || '');
const categoryFilter = ref(props.filters?.category || '');

function applyFilters() {
    router.get(route('transactions.index'), {
        search: search.value || undefined,
        type: typeFilter.value || undefined,
        category: categoryFilter.value || undefined,
    }, { preserveState: true, replace: true });
}

watch([search, typeFilter, categoryFilter], applyFilters);

const deleteId = ref(null);

function destroy() {
    router.delete(route('transactions.destroy', deleteId.value), {
        onSuccess: () => { deleteId.value = null; },
    });
}
</script>

<template>
    <Head :title="t('transactions')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('transactions') }}</h1>
                <Link :href="route('transactions.create')" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 transition-colors">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    {{ t('add_transaction') }}
                </Link>
            </div>
        </template>

        <div class="space-y-4">
            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="w-full sm:w-64">
                    <SearchInput v-model="search" :placeholder="t('search')" />
                </div>
                <select v-model="typeFilter" class="rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                    <option value="">{{ t('all') }}</option>
                    <option value="income">{{ t('income') }}</option>
                    <option value="expense">{{ t('expense') }}</option>
                </select>
                <select v-model="categoryFilter" class="rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                    <option value="">{{ t('all_categories') }}</option>
                    <option value="subscription">{{ t('subscription') }}</option>
                    <option value="donation">{{ t('donation') }}</option>
                    <option value="equipment">{{ t('equipment') }}</option>
                    <option value="salary">{{ t('job') }}</option>
                    <option value="debt_payment">{{ t('debt') }}</option>
                </select>
            </div>

            <!-- Table -->
            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('date') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('type') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('category') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('description') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('status') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('amount') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="tx in transactions.data" :key="tx.id" class="hover:bg-slate-50 dark:hover:bg-slate-800">
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ tx.transaction_date ? new Date(tx.transaction_date).toLocaleDateString() : '-' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <Badge :label="tx.transaction_type === 'income' ? t('income') : t('expense')" :color="tx.transaction_type === 'income' ? 'emerald' : 'rose'" />
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{{ tx.category }}</td>
                                <td class="max-w-xs truncate px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ tx.description || '-' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <Badge :label="tx.status || '-'" :color="tx.status === 'Paid' ? 'emerald' : tx.status === 'Partial' ? 'amber' : tx.status === 'Exempt' ? 'slate' : 'rose'" />
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-end text-sm font-semibold"
                                    :class="tx.transaction_type === 'income' ? 'text-emerald-700' : 'text-rose-700'">
                                    {{ tx.transaction_type === 'income' ? '+' : '-' }}{{ formatMoney(tx.amount) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-end">
                                    <div class="flex items-center justify-end gap-2">
                                        <Link :href="route('transactions.edit', tx.id)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('edit') }}</Link>
                                        <button @click="deleteId = tx.id" class="text-sm text-rose-500 hover:text-rose-700">{{ t('delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!transactions.data?.length">
                                <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-4">
                    <Pagination :links="transactions" />
                </div>
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
