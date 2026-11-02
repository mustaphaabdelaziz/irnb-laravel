<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import { useCan } from '@/Composables/useCan';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t } = useI18n();
const { can } = useCan();

defineProps({ types: Array });

const editingId = ref(null);
const deleteId = ref(null);
const form = useForm({ name: '' });
const editForm = useForm({ name: '', sort_order: 0 });

function add() {
    form.post(route('activity-types.store'), { onSuccess: () => form.reset() });
}

function startEdit(type) {
    editingId.value = type.id;
    editForm.name = type.name;
    editForm.sort_order = type.sort_order;
}

function saveEdit(id) {
    editForm.put(route('activity-types.update', id), { onSuccess: () => { editingId.value = null; } });
}

function destroy() {
    router.delete(route('activity-types.destroy', deleteId.value), { onFinish: () => { deleteId.value = null; } });
}
</script>

<template>
    <Head :title="t('activity_types')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('activity_types') }}</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <form v-if="can('activity_types', 'add')" @submit.prevent="add" class="flex flex-wrap items-end gap-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="min-w-48 flex-1">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }}</label>
                    <TextInput v-model="form.name" class="mt-1 w-full" required />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <PrimaryButton :disabled="form.processing">{{ t('add') }}</PrimaryButton>
            </form>

            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="w-20 px-4 py-3 text-center text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">#</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('activities') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="type in types" :key="type.id">
                            <td class="px-4 py-3 text-center text-sm text-slate-500 dark:text-slate-400">
                                <TextInput v-if="editingId === type.id" v-model="editForm.sort_order" type="number" min="0" class="w-16" />
                                <span v-else>{{ type.sort_order }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div v-if="editingId === type.id" class="space-y-1">
                                    <TextInput v-model="editForm.name" class="w-full" />
                                    <InputError :message="editForm.errors.name" />
                                </div>
                                <span v-else class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ type.name }}</span>
                            </td>
                            <td class="px-4 py-3 text-center text-sm text-slate-600 dark:text-slate-300">{{ type.activities_count }}</td>
                            <td class="px-4 py-3 text-end">
                                <div v-if="editingId === type.id" class="flex justify-end gap-2">
                                    <button @click="saveEdit(type.id)" class="text-sm text-primary-600 hover:text-primary-800 dark:text-primary-300 dark:hover:text-primary-200">{{ t('save') }}</button>
                                    <button @click="editingId = null" class="text-sm text-slate-500 dark:text-slate-400">{{ t('cancel') }}</button>
                                </div>
                                <div v-else class="flex justify-end gap-2">
                                    <IconButton v-if="can('activity_types', 'edit')" icon="pencil" :label="t('edit')" plain size="sm" @click="startEdit(type)" />
                                    <IconButton v-if="can('activity_types', 'delete')" icon="trash" :label="t('delete')" variant="danger" plain size="sm" :disabled="type.activities_count > 0" @click="deleteId = type.id" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!types?.length">
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
