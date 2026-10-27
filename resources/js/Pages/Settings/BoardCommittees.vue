<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t } = useI18n();

defineProps({ committees: Array });

const editingId = ref(null);
const deleteId = ref(null);

const form = useForm({ name: '', sort_order: 0 });
const editForm = useForm({ name: '', sort_order: 0 });

function addCommittee() {
    form.post(route('board-committees.store'), { onSuccess: () => form.reset() });
}
function startEdit(c) {
    editingId.value = c.id;
    editForm.clearErrors();
    editForm.name = c.name; editForm.sort_order = c.sort_order || 0;
}
function saveEdit(id) {
    editForm.put(route('board-committees.update', id), { onSuccess: () => { editingId.value = null; } });
}
function destroy() {
    router.delete(route('board-committees.destroy', deleteId.value), { onSuccess: () => { deleteId.value = null; } });
}
</script>

<template>
    <Head :title="t('board_committees')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('board_committees') }}</h1>
        </template>

        <div class="mx-auto max-w-2xl space-y-6">
            <form @submit.prevent="addCommittee" class="flex items-end gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="flex-1">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }}</label>
                    <TextInput v-model="form.name" class="mt-1 w-full" :placeholder="t('board.committee_name_placeholder')" required />
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
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('board.committee') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('order') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('board_members') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="c in committees" :key="c.id">
                            <td class="px-4 py-3">
                                <template v-if="editingId === c.id">
                                    <TextInput v-model="editForm.name" class="w-full" :placeholder="t('name')" />
                                    <InputError :message="editForm.errors.name" class="mt-1" />
                                </template>
                                <span v-else class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ c.name }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <TextInput v-if="editingId === c.id" v-model="editForm.sort_order" type="number" class="w-16" />
                                <span v-else class="text-sm text-slate-500">{{ c.sort_order }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ c.members_count }}</span>
                            </td>
                            <td class="px-4 py-3 text-end">
                                <div v-if="editingId === c.id" class="flex justify-end gap-2">
                                    <button @click="saveEdit(c.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('save') }}</button>
                                    <button @click="editingId = null" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">{{ t('cancel') }}</button>
                                </div>
                                <div v-else class="flex justify-end gap-2">
                                    <IconButton icon="pencil" :label="t('edit')" plain size="sm" @click="startEdit(c)" />
                                    <IconButton icon="trash" :label="c.members_count > 0 ? t('board.committee_in_use') : t('delete')" variant="danger" plain size="sm" :disabled="c.members_count > 0" @click="deleteId = c.id" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!committees?.length">
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
