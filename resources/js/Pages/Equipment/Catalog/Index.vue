<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatStrip from '@/Components/Dashboard/StatStrip.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Pagination from '@/Components/Pagination.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import IconButton from '@/Components/IconButton.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { useBulkSelection } from '@/Composables/useBulkSelection';
import { computed, ref } from 'vue';
import { asList, useListFilters } from '@/Composables/useListFilters';
import MultiSelectFilter from '@/Components/MultiSelectFilter.vue';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    strip: { type: Array, default: () => [] },
    catalogs: Object,
    filters: Object,
    // Managed in Settings > Equipment Categories.
    equipmentCategories: { type: Array, default: () => [] },
});

const search = ref(props.filters?.search || '');
// Multi-select: a catalog in any checked category is listed.
const categoryFilter = ref(asList(props.filters?.category));

const { params: filterParams, loading: filtering } = useListFilters('equipment.catalogs.index', () => ({
    search: search.value,
    category: categoryFilter.value,
}), { only: ['catalogs', 'filters'] });

const catalogRows = computed(() => props.catalogs?.data ?? []);
const {
    selected,
    allSelected,
    toggleAll,
    toggleOne,
    clear: clearSelection,
} = useBulkSelection(catalogRows, filterParams);

const deleteId = ref(null);
const bulkDeletePending = ref(false);

function destroy() {
    router.delete(route('equipment.catalogs.destroy', deleteId.value), {
        onSuccess: () => { deleteId.value = null; },
    });
}

function bulkDestroy() {
    router.post(route('equipment.catalogs.bulk-destroy'), { ids: selected.value }, {
        onSuccess: () => {
            bulkDeletePending.value = false;
            clearSelection();
        },
    });
}

// --- Equipment (catalog) import (.xlsx or .csv, read on the server) ---
const showImport = ref(false);
const importForm = useForm({ file: null });

function onImportFile(e) {
    importForm.clearErrors();
    importForm.file = e.target.files?.[0] ?? null;
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
                    <IconButton :href="route('equipment.catalogs.create')" icon="plus" :label="t('add_equipment')" variant="primary" />
                    <IconButton icon="upload" :label="t('import')" @click="showImport = true" />
                    <ExportMenu :href="route('equipment.catalogs.export')" :label="t('export')" />
                    <IconButton :href="route('equipment.inventory')" icon="clipboard" :label="t('inventory_report')" />
                </div>
            </div>
        </template>

        <StatStrip :tiles="strip || []" class="mb-4" />

        <div class="space-y-4">
            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="w-full sm:w-64">
                    <SearchInput v-model="search" :loading="filtering" :placeholder="t('search')" />
                </div>
                <MultiSelectFilter v-model="categoryFilter" collapse-all :options="equipmentCategories.map((cat) => ({ value: cat, label: cat }))" :label="t('category')" :placeholder="t('all_categories')" class="min-w-0 flex-1 sm:w-52 sm:flex-none" />
            </div>

            <div v-if="selected.length" class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-primary-50 px-4 py-2.5 ring-1 ring-primary-200 dark:bg-primary-900/20 dark:ring-primary-800">
                <span class="text-sm font-medium text-primary-800 dark:text-primary-200">
                    {{ t('selected_count', { count: selected.length }) }}
                </span>
                <IconButton icon="trash" :label="t('delete_selected')" variant="danger" @click="bulkDeletePending = true" />
            </div>

            <!-- Catalog table -->
            <div :class="{ 'opacity-60': filtering }" :aria-busy="filtering" class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 transition-opacity dark:ring-slate-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="w-12 px-4 py-3 text-start">
                                    <input
                                        type="checkbox"
                                        :checked="allSelected"
                                        :aria-label="t('select_all')"
                                        class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800"
                                        @change="toggleAll"
                                    />
                                </th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('category') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('brand') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('price') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('items') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr
                                v-for="cat in catalogs.data"
                                :key="cat.id"
                                class="hover:bg-slate-50 dark:hover:bg-slate-800"
                                :class="selected.includes(cat.id) ? 'bg-primary-50/50 dark:bg-primary-900/10' : ''"
                            >
                                <td class="w-12 px-4 py-3">
                                    <input
                                        type="checkbox"
                                        :checked="selected.includes(cat.id)"
                                        :aria-label="t('select_item', { name: cat.name })"
                                        class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800"
                                        @change="toggleOne(cat.id)"
                                    />
                                </td>
                                <td class="px-4 py-3">
                                    <Link :href="route('equipment.catalogs.show', cat.id)" class="text-sm font-medium text-primary-600 hover:text-primary-800">{{ cat.name }}</Link>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ cat.category }}</td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ cat.brand || '-' }}</td>
                                <td class="px-4 py-3 text-end text-sm">{{ formatMoney(cat.purchase_price) }}</td>
                                <!-- Units is the figure that means something; a lot row can hold 100 dossards. -->
                                <td class="px-4 py-3 text-end text-sm">
                                    <span class="font-semibold">{{ cat.units_total ?? 0 }}</span>
                                    <span class="ms-1 text-xs text-slate-400">{{ t('equipment.units') }}</span>
                                    <span v-if="(cat.items_count ?? 0) > 1" class="block text-xs text-slate-400">
                                        {{ cat.items_count }} {{ t('equipment.lots') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-end">
                                    <div class="flex items-center justify-end gap-1">
                                        <IconButton :href="route('equipment.catalogs.show', cat.id)" icon="eye" :label="t('details')" variant="primary" plain size="sm" />
                                        <IconButton :href="route('equipment.catalogs.edit', cat.id)" icon="pencil" :label="t('edit')" plain size="sm" />
                                        <IconButton icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = cat.id" />
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!catalogs.data?.length">
                                <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-4"><Pagination :links="catalogs" /></div>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
        <ConfirmModal
            :show="bulkDeletePending"
            :message="t('confirm_bulk_material_delete', { count: selected.length })"
            :confirm-label="t('delete_selected')"
            @confirm="bulkDestroy"
            @cancel="bulkDeletePending = false"
        />

        <!-- Import equipments modal -->
        <Teleport to="body">
            <div v-if="showImport" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="showImport = false">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('import') }} — {{ t('equipments') }}</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('import_equipments_hint') }}</p>
                    <form @submit.prevent="submitImport" class="mt-4 space-y-4">
                        <input
                            type="file"
                            accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            @change="onImportFile"
                            required
                            class="w-full text-sm text-slate-600 dark:text-slate-300 file:me-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100"
                        />
                        <p v-if="importForm.errors.file" class="text-sm text-rose-600">{{ importForm.errors.file }}</p>
                        <div class="flex items-center justify-between gap-3 pt-2">
                            <ExportMenu :href="route('equipment.catalogs.import.template')" :label="t('download_template')" align="left" />
                            <div class="flex gap-2">
                                <button type="button" @click="showImport = false" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                                <button type="submit" :disabled="importForm.processing || !importForm.file" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">{{ t('import') }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
