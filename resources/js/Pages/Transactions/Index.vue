<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Icon from '@/Components/Icon.vue';
import StatCard from '@/Components/StatCard.vue';
import CategoryManager from '@/Components/CategoryManager.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { ref, watch, computed } from 'vue';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    transactions: Object,
    filters: Object,
    stats: Object,
    financeCategories: { type: Array, default: () => [] },
});

const search = ref(props.filters?.search || '');
const typeFilter = ref(props.filters?.type || '');
const financeCategoryFilter = ref(props.filters?.finance_category_id || '');
const showCategories = ref(false);

// Export the current view (respects the active filters).
const exportUrl = computed(() => route('transactions.export', {
    search: search.value || undefined,
    type: typeFilter.value || undefined,
    finance_category_id: financeCategoryFilter.value || undefined,
    fiscal_year: props.filters?.fiscal_year || undefined,
    status: props.filters?.status || undefined,
}));

const importInput = ref(null);
function onImport(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    router.post(route('transactions.import.store'), { file }, {
        forceFormData: true,
        onFinish: () => { if (importInput.value) importInput.value.value = ''; },
    });
}

function applyFilters() {
    router.get(route('transactions.index'), {
        search: search.value || undefined,
        type: typeFilter.value || undefined,
        finance_category_id: financeCategoryFilter.value || undefined,
    }, { preserveState: true, replace: true });
}

watch([search, typeFilter, financeCategoryFilter], applyFilters);

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
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('transactions') }}</h1>
                <div class="flex flex-wrap items-center gap-2">
                    <a :href="route('transactions.import.template')" class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-500 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-400 dark:ring-slate-800" :title="t('template') ? t('template') : 'Template'"><Icon name="document" /></a>
                    <button @click="importInput?.click()" class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800"><Icon name="upload" /> {{ t('import') }}</button>
                    <input ref="importInput" type="file" accept=".xlsx,.xls,.csv" class="hidden" @change="onImport" />
                    <a :href="exportUrl" class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800"><Icon name="download" /> {{ t('export') }}</a>
                    <button @click="showCategories = true" class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800"><Icon name="settings" /> {{ t('manage_categories') }}</button>
                    <Link :href="route('transactions.create')" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 transition-colors">
                        <Icon name="plus" /> {{ t('add_transaction') }}
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <!-- Stats -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard :label="t('total_income')" :value="formatMoney(stats?.income || 0)" icon="money" color="emerald" suffix="DZD" />
                <StatCard :label="t('total_expense')" :value="formatMoney(stats?.expense || 0)" icon="money" color="rose" suffix="DZD" />
                <StatCard :label="t('net_balance')" :value="formatMoney(stats?.net || 0)" icon="dashboard" :color="(stats?.net || 0) >= 0 ? 'emerald' : 'rose'" suffix="DZD" />
                <StatCard :label="t('outstanding_debts')" :value="formatMoney(stats?.debts || 0)" icon="money" color="amber" suffix="DZD" />
            </div>

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
                <select v-model="financeCategoryFilter" class="rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                    <option value="">{{ t('all_categories') }}</option>
                    <optgroup :label="t('income')">
                        <option v-for="c in financeCategories.filter((x) => x.type === 'income')" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </optgroup>
                    <optgroup :label="t('expense')">
                        <option v-for="c in financeCategories.filter((x) => x.type === 'expense')" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </optgroup>
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
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-700 dark:text-slate-200">
                                    <span v-if="tx.finance_category" class="inline-flex items-center gap-1.5">
                                        <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: tx.finance_category.color || '#94a3b8' }"></span>
                                        {{ tx.finance_category.name }}
                                    </span>
                                    <span v-else>{{ tx.category }}</span>
                                </td>
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

        <CategoryManager :show="showCategories" :categories="financeCategories" @close="showCategories = false" />
    </AuthenticatedLayout>
</template>
