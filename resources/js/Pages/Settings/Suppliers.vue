<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan.js';
import { computed, ref } from 'vue';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({ suppliers: Array });

const FIELDS = ['name', 'activity', 'address', 'phone', 'bank_name', 'rib', 'notes'];
const blank = () => ({ name: '', activity: '', address: '', phone: '', bank_name: '', rib: '', notes: '', is_active: true });

const query = ref('');
const editingId = ref(null);
const deleteId = ref(null);
const form = useForm(blank());
const editForm = useForm(blank());

const shown = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return props.suppliers;
    return props.suppliers.filter((s) => [s.name, s.activity, s.rib].some((v) => (v || '').toLowerCase().includes(q)));
});

function add() {
    form.post(route('suppliers.store'), { preserveScroll: true, onSuccess: () => form.reset() });
}

function startEdit(supplier) {
    editingId.value = supplier.id;
    FIELDS.forEach((f) => { editForm[f] = supplier[f] || ''; });
    editForm.is_active = supplier.is_active;
}

function saveEdit(id) {
    editForm.put(route('suppliers.update', id), { preserveScroll: true, onSuccess: () => { editingId.value = null; } });
}

function destroy() {
    router.delete(route('suppliers.destroy', deleteId.value), { preserveScroll: true, onFinish: () => { deleteId.value = null; } });
}
</script>

<template>
    <Head :title="t('suppliers')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('suppliers') }}</h1>
        </template>

        <div class="mx-auto max-w-5xl space-y-6">
            <form v-if="can('suppliers', 'add')" @submit.prevent="add" class="grid grid-cols-1 gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:grid-cols-3 dark:bg-slate-900 dark:ring-slate-800">
                <div v-for="f in ['name', 'activity', 'phone', 'bank_name', 'rib', 'address']" :key="f">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('supplier.field.' + f) }}</label>
                    <TextInput v-model="form[f]" class="mt-1 w-full" :required="f === 'name'" :dir="f === 'rib' || f === 'phone' ? 'ltr' : undefined" />
                    <InputError :message="form.errors[f]" class="mt-1" />
                </div>
                <div class="sm:col-span-3 flex justify-end">
                    <PrimaryButton :disabled="form.processing">{{ t('add') }}</PrimaryButton>
                </div>
            </form>

            <TextInput v-model="query" class="w-full sm:w-72" :placeholder="t('supplier.filter_placeholder')" />

            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">
                            <th class="px-4 py-3 text-start">{{ t('supplier.field.name') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('supplier.field.activity') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('supplier.field.bank_name') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('supplier.field.rib') }}</th>
                            <th class="px-4 py-3 text-end">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="s in shown" :key="s.id" :class="{ 'opacity-60': !s.is_active }">
                            <template v-if="editingId === s.id">
                                <td colspan="4" class="px-4 py-3">
                                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                        <div v-for="f in ['name', 'activity', 'phone', 'bank_name', 'rib', 'address']" :key="f">
                                            <TextInput v-model="editForm[f]" class="w-full" :placeholder="t('supplier.field.' + f)" :dir="f === 'rib' || f === 'phone' ? 'ltr' : undefined" />
                                            <InputError :message="editForm.errors[f]" />
                                        </div>
                                        <label class="flex items-center gap-2 text-xs"><input v-model="editForm.is_active" type="checkbox" class="rounded" /> {{ t('active') }}</label>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-end">
                                    <div class="flex justify-end gap-2">
                                        <button @click="saveEdit(s.id)" class="text-sm text-primary-600 dark:text-primary-300">{{ t('save') }}</button>
                                        <button @click="editingId = null" class="text-sm text-slate-500 dark:text-slate-400">{{ t('cancel') }}</button>
                                    </div>
                                </td>
                            </template>
                            <template v-else>
                                <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">{{ s.name }}</td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ s.activity }}</td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ s.bank_name }}</td>
                                <td class="px-4 py-3 font-mono text-slate-600 dark:text-slate-300"><bdi dir="ltr">{{ s.rib }}</bdi></td>
                                <td class="px-4 py-3 text-end">
                                    <div class="flex justify-end gap-2">
                                        <IconButton v-if="can('suppliers', 'edit')" icon="pencil" :label="t('edit')" plain size="sm" @click="startEdit(s)" />
                                        <IconButton v-if="can('suppliers', 'delete')" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = s.id" />
                                    </div>
                                </td>
                            </template>
                        </tr>
                        <tr v-if="!shown.length">
                            <td colspan="5" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
