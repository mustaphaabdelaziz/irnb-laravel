<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t } = useI18n();

defineProps({
    playerStatuses: { type: Array, default: () => [] },
});

const editingId = ref(null);
const deleteId = ref(null);

const blank = { name: '', name_ar: '', name_fr: '', name_en: '', sort_order: 0, is_active: true };

const form = useForm({ ...blank });
const editForm = useForm({ ...blank });

function addStatus() {
    form.post(route('player-statuses.store'), {
        onSuccess: () => form.reset(),
    });
}

function startEdit(status) {
    editingId.value = status.id;
    editForm.name = status.name;
    editForm.name_ar = status.name_ar || '';
    editForm.name_fr = status.name_fr || '';
    editForm.name_en = status.name_en || '';
    editForm.sort_order = status.sort_order ?? 0;
    editForm.is_active = !!status.is_active;
    editForm.clearErrors();
}

function saveEdit(id) {
    editForm.put(route('player-statuses.update', id), {
        onSuccess: () => { editingId.value = null; },
    });
}

function destroy() {
    const id = deleteId.value;
    deleteId.value = null;
    router.delete(route('player-statuses.destroy', id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('player_statuses')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('player_statuses') }}</h1>
        </template>

        <div class="mx-auto max-w-4xl space-y-6">
            <!-- Add -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('add') }}</h2>
                <form @submit.prevent="addStatus" class="grid gap-3 sm:grid-cols-4">
                    <div>
                        <InputLabel :value="t('name')" />
                        <TextInput v-model="form.name" class="mt-1 w-full" required />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="العربية" />
                        <TextInput v-model="form.name_ar" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel value="Français" />
                        <TextInput v-model="form.name_fr" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel value="English" />
                        <TextInput v-model="form.name_en" class="mt-1 w-full" />
                    </div>
                    <div class="sm:col-span-4 flex justify-end">
                        <PrimaryButton :disabled="form.processing">{{ t('save') }}</PrimaryButton>
                    </div>
                </form>
            </div>

            <!-- List -->
            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">العربية</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Français</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">English</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('players') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="s in playerStatuses" :key="s.id" :class="{ 'opacity-60': !s.is_active }">
                            <template v-if="editingId === s.id">
                                <td class="px-4 py-2"><TextInput v-model="editForm.name" class="w-full" /></td>
                                <td class="px-4 py-2"><TextInput v-model="editForm.name_ar" class="w-full" /></td>
                                <td class="px-4 py-2"><TextInput v-model="editForm.name_fr" class="w-full" /></td>
                                <td class="px-4 py-2"><TextInput v-model="editForm.name_en" class="w-full" /></td>
                                <td class="px-4 py-2 text-end">
                                    <label class="inline-flex items-center gap-1 text-xs">
                                        <input type="checkbox" v-model="editForm.is_active" class="rounded border-slate-300" />
                                        {{ t('active') }}
                                    </label>
                                </td>
                                <td class="px-4 py-2 text-end">
                                    <button @click="saveEdit(s.id)" class="text-sm text-emerald-600 hover:text-emerald-800">{{ t('save') }}</button>
                                    <button @click="editingId = null" class="ms-3 text-sm text-slate-500">{{ t('cancel') }}</button>
                                </td>
                            </template>
                            <template v-else>
                                <td class="px-4 py-3 text-sm text-slate-900 dark:text-slate-100">
                                    {{ s.name }}
                                    <!-- Imported values that matched nothing are kept but not offered when editing a player. -->
                                    <span v-if="!s.is_active" class="ms-2 rounded bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 text-xs text-slate-500">{{ t('inactive') }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ s.name_ar || '—' }}</td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ s.name_fr || '—' }}</td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ s.name_en || '—' }}</td>
                                <td class="px-4 py-3 text-end text-sm text-slate-600 dark:text-slate-300">{{ s.players_count }}</td>
                                <td class="px-4 py-3 text-end">
                                    <button @click="startEdit(s)" class="text-sm text-slate-500 hover:text-slate-700">{{ t('edit') }}</button>
                                    <!-- Deletion is refused server-side while players reference it. -->
                                    <button v-if="s.players_count === 0" @click="deleteId = s.id" class="ms-3 text-sm text-rose-500 hover:text-rose-700">{{ t('delete') }}</button>
                                </td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
