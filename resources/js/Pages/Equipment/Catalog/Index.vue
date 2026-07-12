<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { ref, watch } from 'vue';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    catalogs: Object,
    filters: Object,
    // Managed in Settings > Equipment Categories.
    equipmentCategories: { type: Array, default: () => [] },
});

const search = ref(props.filters?.search || '');
const categoryFilter = ref(props.filters?.category || '');

function applyFilters() {
    router.get(route('equipment.catalogs.index'), {
        search: search.value || undefined,
        category: categoryFilter.value || undefined,
    }, { preserveState: true, replace: true });
}

watch([search, categoryFilter], applyFilters);

const deleteId = ref(null);

function destroy() {
    router.delete(route('equipment.catalogs.destroy', deleteId.value), {
        onSuccess: () => { deleteId.value = null; },
    });
}

// --- Equipment (catalog) import (CSV + Excel; Excel converted in-browser) ---
const showImport = ref(false);
const importForm = useForm({ file: null });
const importConverting = ref(false);

async function onImportFile(e) {
    const file = e.target.files?.[0];
    importForm.clearErrors();
    if (!file) { importForm.file = null; return; }
    if (!/\.(xlsx|xls)$/i.test(file.name)) { importForm.file = file; return; }
    importConverting.value = true;
    try {
        const XLSX = await import('xlsx');
        const wb = XLSX.read(await file.arrayBuffer(), { type: 'array' });
        const csv = XLSX.utils.sheet_to_csv(wb.Sheets[wb.SheetNames[0]]);
        importForm.file = new File([csv], file.name.replace(/\.(xlsx|xls)$/i, '.csv'), { type: 'text/csv' });
    } catch (err) {
        importForm.file = null;
        importForm.setError('file', t('excel_parse_failed'));
    } finally {
        importConverting.value = false;
    }
}

function submitImport() {
    importForm.post(route('equipment.catalogs.import'), {
        forceFormData: true,
        onSuccess: () => { showImport.value = false; importForm.reset(); },
    });
}

</script>

<template>
    <Head :title="t('equipments')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('equipments') }}</h1>
                <div class="flex flex-wrap gap-2">
                    <Link :href="route('equipment.catalogs.create')" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 transition-colors">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        {{ t('add_equipment') }}
                    </Link>
                    <button @click="showImport = true" class="inline-flex items-center rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        {{ t('import') }}
                    </button>
                    <a :href="route('equipment.catalogs.export')" class="inline-flex items-center rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        {{ t('export') }}
                    </a>
                    <Link :href="route('equipment.inventory')" class="inline-flex items-center rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        {{ t('inventory_report') }}
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="w-full sm:w-64">
                    <SearchInput v-model="search" :placeholder="t('search')" />
                </div>
                <select v-model="categoryFilter" class="rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                    <option value="">{{ t('all_categories') }}</option>
                    <option v-for="cat in equipmentCategories" :key="cat" :value="cat">{{ cat }}</option>
                </select>
            </div>

            <!-- Catalog table -->
            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('category') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('brand') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('price') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('items') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="cat in catalogs.data" :key="cat.id" class="hover:bg-slate-50 dark:hover:bg-slate-800">
                                <td class="px-4 py-3">
                                    <Link :href="route('equipment.catalogs.show', cat.id)" class="text-sm font-medium text-primary-600 hover:text-primary-800">{{ cat.name }}</Link>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ cat.category }}</td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ cat.brand || '-' }}</td>
                                <td class="px-4 py-3 text-end text-sm">{{ formatMoney(cat.purchase_price) }}</td>
                                <td class="px-4 py-3 text-end text-sm font-semibold">{{ cat.items_count ?? 0 }}</td>
                                <td class="px-4 py-3 text-end">
                                    <div class="flex items-center justify-end gap-2">
                                        <Link :href="route('equipment.catalogs.show', cat.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('details') }}</Link>
                                        <Link :href="route('equipment.catalogs.edit', cat.id)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('edit') }}</Link>
                                        <button @click="deleteId = cat.id" class="text-sm text-rose-500 hover:text-rose-700">{{ t('delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!catalogs.data?.length">
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-4"><Pagination :links="catalogs" /></div>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />

        <!-- Import equipments modal -->
        <Teleport to="body">
            <div v-if="showImport" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="showImport = false">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('import') }} — {{ t('equipments') }}</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('import_equipments_hint') }}</p>
                    <form @submit.prevent="submitImport" class="mt-4 space-y-4">
                        <input
                            type="file"
                            accept=".csv,.xlsx,.xls,text/csv"
                            @change="onImportFile"
                            required
                            class="w-full text-sm text-slate-600 dark:text-slate-300 file:me-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100"
                        />
                        <p v-if="importConverting" class="text-sm text-slate-500 dark:text-slate-400">{{ t('converting_excel') }}</p>
                        <p v-if="importForm.errors.file" class="text-sm text-rose-600">{{ importForm.errors.file }}</p>
                        <div class="flex items-center justify-between gap-3 pt-2">
                            <a :href="route('equipment.catalogs.import.template')" class="text-sm font-medium text-primary-600 hover:underline">{{ t('download_template') }}</a>
                            <div class="flex gap-2">
                                <button type="button" @click="showImport = false" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                                <button type="submit" :disabled="importForm.processing || importConverting || !importForm.file" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">{{ t('import') }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
