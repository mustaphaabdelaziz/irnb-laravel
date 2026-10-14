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

defineProps({ venues: Array });

const editingId = ref(null);
const deleteId = ref(null);
const form = useForm({ name: '', address: '', is_home: false });
const editForm = useForm({ name: '', address: '', is_home: false });

function add() {
    form.post(route('venues.store'), { onSuccess: () => form.reset() });
}

function startEdit(venue) {
    editingId.value = venue.id;
    editForm.name = venue.name;
    editForm.address = venue.address || '';
    editForm.is_home = venue.is_home;
}

function saveEdit(id) {
    editForm.put(route('venues.update', id), { onSuccess: () => { editingId.value = null; } });
}

function destroy() {
    router.delete(route('venues.destroy', deleteId.value), { onFinish: () => { deleteId.value = null; } });
}
</script>

<template>
    <Head :title="t('venues')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('venues') }}</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <form @submit.prevent="add" class="flex flex-wrap items-end gap-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="min-w-48 flex-1">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }}</label>
                    <TextInput v-model="form.name" class="mt-1 w-full" required />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <div class="min-w-48 flex-1">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('address') }}</label>
                    <TextInput v-model="form.address" class="mt-1 w-full" />
                </div>
                <label class="flex items-center gap-2 pb-2 text-sm text-slate-700 dark:text-slate-200">
                    <input v-model="form.is_home" type="checkbox" class="rounded border-slate-300 dark:border-slate-700" />
                    {{ t('comp.home_ground') }}
                </label>
                <PrimaryButton :disabled="form.processing">{{ t('add') }}</PrimaryButton>
            </form>

            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('address') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('games') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="venue in venues" :key="venue.id">
                            <td class="px-4 py-3">
                                <div v-if="editingId === venue.id" class="space-y-1">
                                    <TextInput v-model="editForm.name" class="w-full" />
                                    <InputError :message="editForm.errors.name" />
                                    <label class="flex items-center gap-2 text-xs"><input v-model="editForm.is_home" type="checkbox" class="rounded" /> {{ t('comp.home_ground') }}</label>
                                </div>
                                <div v-else class="flex items-center gap-2 text-sm font-medium text-slate-900 dark:text-slate-100">
                                    {{ venue.name }}
                                    <span v-if="venue.is_home" class="rounded bg-primary-50 px-1.5 py-0.5 text-xs text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">{{ t('comp.home_ground') }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                <TextInput v-if="editingId === venue.id" v-model="editForm.address" class="w-full" />
                                <span v-else>{{ venue.address }}</span>
                            </td>
                            <td class="px-4 py-3 text-center text-sm text-slate-600 dark:text-slate-300">{{ venue.games_count }}</td>
                            <td class="px-4 py-3 text-end">
                                <div v-if="editingId === venue.id" class="flex justify-end gap-2">
                                    <button @click="saveEdit(venue.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('save') }}</button>
                                    <button @click="editingId = null" class="text-sm text-slate-500 dark:text-slate-400">{{ t('cancel') }}</button>
                                </div>
                                <div v-else class="flex justify-end gap-2">
                                    <IconButton icon="pencil" :label="t('edit')" plain size="sm" @click="startEdit(venue)" />
                                    <IconButton icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = venue.id" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!venues?.length">
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
