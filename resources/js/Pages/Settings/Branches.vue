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

const props = defineProps({
    branches: Array,
});

const editingId = ref(null);
const deleteId = ref(null);

const form = useForm({ name: '', name_ar: '', name_fr: '', name_en: '', description: '' });
const editForm = useForm({ name: '', name_ar: '', name_fr: '', name_en: '', description: '' });

function addBranch() {
    form.post(route('branches.store'), {
        onSuccess: () => form.reset(),
    });
}

function startEdit(branch) {
    editingId.value = branch.id;
    editForm.name = branch.name;
    editForm.name_ar = branch.name_ar || '';
    editForm.name_fr = branch.name_fr || '';
    editForm.name_en = branch.name_en || '';
    editForm.description = branch.description || '';
}

function saveEdit(id) {
    editForm.put(route('branches.update', id), {
        onSuccess: () => { editingId.value = null; },
    });
}

function destroy() {
    router.delete(route('branches.destroy', deleteId.value), {
        onSuccess: () => { deleteId.value = null; },
    });
}
</script>

<template>
    <Head :title="t('branches')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('branches') }}</h1>
        </template>

        <div class="mx-auto max-w-2xl space-y-6">
            <!-- Add form -->
            <form @submit.prevent="addBranch" class="space-y-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }} <span class="text-xs text-slate-400">({{ t('default') }})</span></label>
                        <TextInput v-model="form.name" class="mt-1 w-full" :placeholder="t('name')" required />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('description') }}</label>
                        <TextInput v-model="form.description" class="mt-1 w-full" :placeholder="t('description')" />
                    </div>
                    <div>
                        <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }} (العربية)</label>
                        <TextInput v-model="form.name_ar" class="mt-1 w-full" placeholder="الاسم" />
                    </div>
                    <div>
                        <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }} (Français)</label>
                        <TextInput v-model="form.name_fr" class="mt-1 w-full" placeholder="Nom" />
                    </div>
                    <div>
                        <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }} (English)</label>
                        <TextInput v-model="form.name_en" class="mt-1 w-full" placeholder="Name" />
                    </div>
                </div>
                <div class="flex justify-end">
                    <PrimaryButton :disabled="form.processing">{{ t('add') }}</PrimaryButton>
                </div>
            </form>

            <!-- List -->
            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('members') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="branch in branches" :key="branch.id">
                            <td class="px-4 py-3">
                                <div v-if="editingId === branch.id" class="space-y-1.5">
                                    <TextInput v-model="editForm.name" class="w-full" :placeholder="t('name')" />
                                    <TextInput v-model="editForm.name_ar" class="w-full" placeholder="العربية" />
                                    <TextInput v-model="editForm.name_fr" class="w-full" placeholder="Français" />
                                    <TextInput v-model="editForm.name_en" class="w-full" placeholder="English" />
                                    <InputError :message="editForm.errors.name" class="mt-1" />
                                </div>
                                <span v-else class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ branch.localized_name || branch.name }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ branch.players_count }}</td>
                            <td class="px-4 py-3 text-end">
                                <div v-if="editingId === branch.id" class="flex justify-end gap-2">
                                    <button @click="saveEdit(branch.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('save') }}</button>
                                    <button @click="editingId = null" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('cancel') }}</button>
                                </div>
                                <div v-else class="flex justify-end gap-2">
                                    <button @click="startEdit(branch)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('edit') }}</button>
                                    <button @click="deleteId = branch.id" class="text-sm text-rose-500 hover:text-rose-700">{{ t('delete') }}</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!branches?.length">
                            <td colspan="3" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
