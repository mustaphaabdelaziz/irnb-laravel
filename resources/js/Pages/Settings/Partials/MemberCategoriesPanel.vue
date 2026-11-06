<script setup>
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import { useCan } from '@/Composables/useCan';
import { useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({
    categories: { type: Array, default: () => [] },
    branchId: { type: Number, default: null }, // null: a club with no branches
});

const editingId = ref(null);
const deleteId = ref(null);

const form = useForm({ branch_id: props.branchId, name: '', name_ar: '', name_fr: '', name_en: '', description: '' });
const editForm = useForm({ name: '', name_ar: '', name_fr: '', name_en: '', description: '' });

function addCategory() {
    form.post(route('categories.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('name', 'name_ar', 'name_fr', 'name_en', 'description'),
    });
}

function startEdit(cat) {
    editingId.value = cat.id;
    editForm.clearErrors();
    editForm.name = cat.name;
    editForm.name_ar = cat.name_ar || '';
    editForm.name_fr = cat.name_fr || '';
    editForm.name_en = cat.name_en || '';
    editForm.description = cat.description || '';
}

function saveEdit(id) {
    editForm.put(route('categories.update', id), {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
    });
}

function toggle(cat) {
    router.post(route('categories.toggle-active', cat.id), {}, { preserveScroll: true });
}

function destroy() {
    router.delete(route('categories.destroy', deleteId.value), {
        preserveScroll: true,
        onSuccess: () => { deleteId.value = null; },
    });
}
</script>

<template>
    <div class="space-y-6">
        <!-- Add form -->
        <form v-if="can('categories', 'add')" @submit.prevent="addCategory" class="space-y-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
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
        <div class="overflow-x-auto rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
            <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                <thead class="bg-slate-50 dark:bg-slate-950">
                    <tr>
                        <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                        <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('description') }}</th>
                        <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('members') }}</th>
                        <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="cat in categories" :key="cat.id">
                        <td class="px-4 py-3">
                            <div v-if="editingId === cat.id" class="space-y-1.5">
                                <TextInput v-model="editForm.name" class="w-full" :placeholder="t('name')" />
                                <TextInput v-model="editForm.name_ar" class="w-full" placeholder="العربية" />
                                <TextInput v-model="editForm.name_fr" class="w-full" placeholder="Français" />
                                <TextInput v-model="editForm.name_en" class="w-full" placeholder="English" />
                                <InputError :message="editForm.errors.name" class="mt-1" />
                            </div>
                            <template v-else>
                                <span class="text-sm font-medium text-slate-900 dark:text-slate-100" :class="{ 'opacity-60': cat.is_active === false }">{{ cat.localized_name || cat.name }}</span>
                                <span v-if="cat.is_active === false" class="ms-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('category_tab.inactive') }}</span>
                            </template>
                        </td>
                        <td class="px-4 py-3">
                            <TextInput v-if="editingId === cat.id" v-model="editForm.description" class="w-full" />
                            <span v-else class="text-sm text-slate-600 dark:text-slate-300">{{ cat.description || '-' }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ cat.registrations_count }}</td>
                        <td class="px-4 py-3 text-end">
                            <div v-if="editingId === cat.id" class="flex justify-end gap-2">
                                <button @click="saveEdit(cat.id)" class="text-sm text-primary-600 hover:text-primary-800 dark:text-primary-300 dark:hover:text-primary-200">{{ t('save') }}</button>
                                <button @click="editingId = null" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('cancel') }}</button>
                            </div>
                            <div v-else class="flex justify-end gap-2">
                                <IconButton v-if="can('categories', 'edit')" :icon="cat.is_active === false ? 'check' : 'close'" :label="cat.is_active === false ? t('category_tab.activate') : t('category_tab.deactivate')" plain size="sm" @click="toggle(cat)" />
                                <IconButton v-if="can('categories', 'edit')" icon="pencil" :label="t('edit')" plain size="sm" @click="startEdit(cat)" />
                                <IconButton v-if="can('categories', 'delete')" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = cat.id" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!categories?.length">
                        <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('category_tab.inactive_hint') }}</p>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </div>
</template>
