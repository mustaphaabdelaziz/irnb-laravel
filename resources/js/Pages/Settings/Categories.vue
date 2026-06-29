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
    categories: Array,
});

const editingId = ref(null);
const deleteId = ref(null);

const form = useForm({ name: '', description: '' });
const editForm = useForm({ name: '', description: '' });

function addCategory() {
    form.post(route('categories.store'), {
        onSuccess: () => form.reset(),
    });
}

function startEdit(cat) {
    editingId.value = cat.id;
    editForm.name = cat.name;
    editForm.description = cat.description || '';
}

function saveEdit(id) {
    editForm.put(route('categories.update', id), {
        onSuccess: () => { editingId.value = null; },
    });
}

function destroy() {
    router.delete(route('categories.destroy', deleteId.value), {
        onSuccess: () => { deleteId.value = null; },
    });
}
</script>

<template>
    <Head :title="t('categories')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('categories') }}</h1>
        </template>

        <div class="mx-auto max-w-2xl space-y-6">
            <!-- Add form -->
            <form @submit.prevent="addCategory" class="flex items-end gap-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="flex-1">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }}</label>
                    <TextInput v-model="form.name" class="mt-1 w-full" :placeholder="t('name')" required />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <div class="flex-1">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('description') }}</label>
                    <TextInput v-model="form.description" class="mt-1 w-full" :placeholder="t('description')" />
                </div>
                <PrimaryButton :disabled="form.processing">{{ t('add') }}</PrimaryButton>
            </form>

            <!-- List -->
            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('description') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="cat in categories" :key="cat.id">
                            <td class="px-4 py-3">
                                <TextInput v-if="editingId === cat.id" v-model="editForm.name" class="w-full" />
                                <span v-else class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ cat.name }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <TextInput v-if="editingId === cat.id" v-model="editForm.description" class="w-full" />
                                <span v-else class="text-sm text-slate-600 dark:text-slate-300">{{ cat.description || '-' }}</span>
                            </td>
                            <td class="px-4 py-3 text-end">
                                <div v-if="editingId === cat.id" class="flex justify-end gap-2">
                                    <button @click="saveEdit(cat.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('save') }}</button>
                                    <button @click="editingId = null" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('cancel') }}</button>
                                </div>
                                <div v-else class="flex justify-end gap-2">
                                    <button @click="startEdit(cat)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('edit') }}</button>
                                    <button @click="deleteId = cat.id" class="text-sm text-rose-500 hover:text-rose-700">{{ t('delete') }}</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!categories?.length">
                            <td colspan="3" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
