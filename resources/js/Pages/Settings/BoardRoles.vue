<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t } = useI18n();

defineProps({ roles: Array });

const editingId = ref(null);
const deleteId = ref(null);

const form = useForm({ name: '', label: '', sort_order: 0 });
const editForm = useForm({ name: '', label: '', sort_order: 0 });

// Known base roles have translations; custom ones fall back to their raw name.
function display(r) {
    return r.label || t(r.name);
}
function addRole() {
    form.post(route('board-roles.store'), { onSuccess: () => form.reset() });
}
function startEdit(r) {
    editingId.value = r.id;
    editForm.name = r.name; editForm.label = r.label || ''; editForm.sort_order = r.sort_order || 0;
}
function saveEdit(id) {
    editForm.put(route('board-roles.update', id), { onSuccess: () => { editingId.value = null; } });
}
function destroy() {
    router.delete(route('board-roles.destroy', deleteId.value), { onSuccess: () => { deleteId.value = null; } });
}
</script>

<template>
    <Head :title="t('board_roles')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('board_roles') }}</h1>
        </template>

        <div class="mx-auto max-w-2xl space-y-6">
            <form @submit.prevent="addRole" class="flex items-end gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="flex-1">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }}</label>
                    <TextInput v-model="form.name" class="mt-1 w-full" :placeholder="t('name')" required />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <div class="w-20">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('order') }}</label>
                    <TextInput v-model="form.sort_order" type="number" min="0" class="mt-1 w-full" />
                </div>
                <PrimaryButton :disabled="form.processing">{{ t('add') }}</PrimaryButton>
            </form>

            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('role') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('order') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('board_members') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="r in roles" :key="r.id">
                            <td class="px-4 py-3">
                                <div v-if="editingId === r.id" class="flex gap-2">
                                    <TextInput v-model="editForm.name" class="w-full" :placeholder="t('name')" />
                                </div>
                                <span v-else class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ display(r) }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <TextInput v-if="editingId === r.id" v-model="editForm.sort_order" type="number" class="w-16" />
                                <span v-else class="text-sm text-slate-500">{{ r.sort_order }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ r.members_count }}</span>
                            </td>
                            <td class="px-4 py-3 text-end">
                                <div v-if="editingId === r.id" class="flex justify-end gap-2">
                                    <button @click="saveEdit(r.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('save') }}</button>
                                    <button @click="editingId = null" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">{{ t('cancel') }}</button>
                                </div>
                                <div v-else class="flex justify-end gap-2">
                                    <button @click="startEdit(r)" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">{{ t('edit') }}</button>
                                    <button @click="deleteId = r.id" :disabled="r.members_count > 0" :title="r.members_count > 0 ? t('role_in_use') : ''"
                                        class="text-sm text-rose-500 hover:text-rose-700 disabled:cursor-not-allowed disabled:opacity-30">{{ t('delete') }}</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!roles?.length">
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
