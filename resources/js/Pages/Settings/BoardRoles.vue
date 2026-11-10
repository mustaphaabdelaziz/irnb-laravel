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
import { useCan } from '@/Composables/useCan';

const { t } = useI18n();
const { can } = useCan();

defineProps({ roles: Array });

const editingId = ref(null);
const deleteId = ref(null);

const form = useForm({ name: '', label: '', level: 'member', sort_order: 0 });
const editForm = useForm({ name: '', label: '', level: 'member', sort_order: 0 });
// Organigram row: top (president), bureau (secretary-general, treasurer…), member.
const LEVELS = ['top', 'bureau', 'member'];

// Known base roles have translations; custom ones fall back to their raw name.
function display(r) {
    return r.label || t(r.name);
}
function addRole() {
    form.post(route('board-roles.store'), { onSuccess: () => form.reset() });
}
function startEdit(r) {
    editingId.value = r.id;
    editForm.name = r.name;
    // Edit the name people read; the stored key stays, so members keep their role.
    editForm.label = display(r);
    editForm.level = r.level || 'member';
    editForm.sort_order = r.sort_order || 0;
}
function saveEdit(r) {
    editForm
        .transform((d) => {
            const label = (d.label || '').trim();
            // Typing back the built-in translation means "no custom name".
            return { ...d, name: r.name, label: label && label !== t(r.name) ? label : null };
        })
        .put(route('board-roles.update', r.id), { onSuccess: () => { editingId.value = null; } });
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
            <form v-if="can('board_roles', 'add')" @submit.prevent="addRole" class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="flex-1">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }}</label>
                    <TextInput v-model="form.name" class="mt-1 w-full" :placeholder="t('name')" required data-help="brole-name" />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <div class="w-32">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('board.level_label') }}</label>
                    <select v-model="form.level" data-help="brole-level" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        <option v-for="l in LEVELS" :key="l" :value="l">{{ t(`board.level.${l}`) }}</option>
                    </select>
                </div>
                <div class="w-20">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('order') }}</label>
                    <TextInput v-model="form.sort_order" type="number" min="0" class="mt-1 w-full" data-help="brole-order" />
                </div>
                <PrimaryButton :disabled="form.processing" data-help="brole-add">{{ t('add') }}</PrimaryButton>
            </form>

            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('role') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('board.level_label') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('order') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('board_members') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="r in roles" :key="r.id">
                            <td class="px-4 py-3">
                                <div v-if="editingId === r.id">
                                    <TextInput v-model="editForm.label" class="w-full" :placeholder="t('name')" data-help="brole-edit-name" />
                                    <InputError :message="editForm.errors.label || editForm.errors.name" class="mt-1" />
                                </div>
                                <span v-else class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ display(r) }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <select v-if="editingId === r.id" v-model="editForm.level" class="rounded-md border-gray-300 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                                    <option v-for="l in LEVELS" :key="l" :value="l">{{ t(`board.level.${l}`) }}</option>
                                </select>
                                <span v-else class="text-sm text-slate-500 dark:text-slate-400">{{ t(`board.level.${r.level || 'member'}`) }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <TextInput v-if="editingId === r.id" v-model="editForm.sort_order" type="number" class="w-16" />
                                <span v-else class="text-sm text-slate-500 dark:text-slate-400">{{ r.sort_order }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ r.members_count }}</span>
                            </td>
                            <td class="px-4 py-3 text-end">
                                <div v-if="editingId === r.id" class="flex justify-end gap-2">
                                    <button data-help="brole-edit-save" @click="saveEdit(r)" class="text-sm text-primary-600 hover:text-primary-800 dark:text-primary-300 dark:hover:text-primary-200">{{ t('save') }}</button>
                                    <button @click="editingId = null" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">{{ t('cancel') }}</button>
                                </div>
                                <div v-else class="flex justify-end gap-2">
                                    <IconButton v-if="can('board_roles', 'edit')" icon="pencil" :label="t('edit')" plain size="sm" :data-help="`brole-edit-${r.name}`" @click="startEdit(r)" />
                                    <IconButton v-if="can('board_roles', 'delete')" icon="trash" :label="r.in_use ? t('role_in_use') : t('delete')" variant="danger" plain size="sm" :disabled="r.in_use" @click="deleteId = r.id" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!roles?.length">
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
