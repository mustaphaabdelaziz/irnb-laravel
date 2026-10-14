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

defineProps({ opponents: Array });

const editingId = ref(null);
const deleteId = ref(null);

const form = useForm({ name: '', city: '', logo: null });
// Files only travel in multipart POSTs, so the update is a POST spoofing PUT.
const editForm = useForm({ _method: 'put', name: '', city: '', logo: null });

function add() {
    form.post(route('opponents.store'), { forceFormData: true, onSuccess: () => form.reset() });
}

function startEdit(opponent) {
    editingId.value = opponent.id;
    editForm.name = opponent.name;
    editForm.city = opponent.city || '';
    editForm.logo = null;
}

function saveEdit(id) {
    editForm.post(route('opponents.update', id), { forceFormData: true, onSuccess: () => { editingId.value = null; } });
}

function destroy() {
    router.delete(route('opponents.destroy', deleteId.value), { onFinish: () => { deleteId.value = null; } });
}
</script>

<template>
    <Head :title="t('opponents')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('opponents') }}</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <form @submit.prevent="add" class="flex flex-wrap items-end gap-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="min-w-48 flex-1">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }}</label>
                    <TextInput v-model="form.name" class="mt-1 w-full" required />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <div class="w-40">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('city') }}</label>
                    <TextInput v-model="form.city" class="mt-1 w-full" />
                </div>
                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('comp.logo') }}</label>
                    <input type="file" accept="image/*" class="mt-1 block w-48 text-xs" @change="form.logo = $event.target.files[0]" />
                    <InputError :message="form.errors.logo" class="mt-1" />
                </div>
                <PrimaryButton :disabled="form.processing">{{ t('add') }}</PrimaryButton>
            </form>

            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('city') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('games') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="opponent in opponents" :key="opponent.id">
                            <td class="px-4 py-3">
                                <div v-if="editingId === opponent.id" class="space-y-1">
                                    <TextInput v-model="editForm.name" class="w-full" />
                                    <InputError :message="editForm.errors.name" />
                                    <input type="file" accept="image/*" class="block text-xs" @change="editForm.logo = $event.target.files[0]" />
                                </div>
                                <div v-else class="flex items-center gap-2">
                                    <img v-if="opponent.logo_url" :src="opponent.logo_url" alt="" class="h-7 w-7 rounded object-contain" />
                                    <span class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ opponent.name }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                <TextInput v-if="editingId === opponent.id" v-model="editForm.city" class="w-full" />
                                <span v-else>{{ opponent.city }}</span>
                            </td>
                            <td class="px-4 py-3 text-center text-sm text-slate-600 dark:text-slate-300">{{ opponent.games_count }}</td>
                            <td class="px-4 py-3 text-end">
                                <div v-if="editingId === opponent.id" class="flex justify-end gap-2">
                                    <button @click="saveEdit(opponent.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('save') }}</button>
                                    <button @click="editingId = null" class="text-sm text-slate-500 dark:text-slate-400">{{ t('cancel') }}</button>
                                </div>
                                <div v-else class="flex justify-end gap-2">
                                    <IconButton icon="pencil" :label="t('edit')" plain size="sm" @click="startEdit(opponent)" />
                                    <IconButton icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = opponent.id" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!opponents?.length">
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
