<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    documentTypes: { type: Array, default: () => [] },
});

// Every field the server accepts, with the value a new type starts from.
const FIELDS = {
    name: '',
    name_ar: '',
    name_fr: '',
    name_en: '',
    is_required: false,
    validity: 'none',
    max_age: '',
    sort_order: 0,
    is_active: true,
};

const form = useForm({ ...FIELDS });
// null = modal closed, 0 = adding, otherwise the id being edited.
const editing = ref(null);
const deleteId = ref(null);

const validityLabels = computed(() => ({
    none: t('doc_validity_none'),
    season: t('doc_validity_season'),
    date: t('doc_validity_date'),
}));

const inputClass = 'mt-1 w-full rounded-lg border-slate-300 text-slate-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-500/40 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100';

// The full payload for a type (or for a blank one): the server expects every
// field on update, and an empty age box means "every age".
function payloadOf(type) {
    return Object.fromEntries(Object.keys(FIELDS).map((key) => [
        key,
        key === 'max_age' ? (type.max_age ?? '') : (type[key] ?? FIELDS[key]),
    ]));
}

function openCreate() {
    Object.assign(form, payloadOf(FIELDS));
    form.clearErrors();
    editing.value = 0;
}

function openEdit(type) {
    Object.assign(form, payloadOf(type));
    form.clearErrors();
    editing.value = type.id;
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => { editing.value = null; } };

    if (editing.value) {
        form.put(route('document-types.update', editing.value), options);
    } else {
        form.post(route('document-types.store'), options);
    }
}

function toggleActive(type) {
    router.put(route('document-types.update', type.id), { ...payloadOf(type), is_active: !type.is_active }, { preserveScroll: true });
}

function destroy() {
    const id = deleteId.value;
    deleteId.value = null;
    router.delete(route('document-types.destroy', id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('document_types')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('document_types') }}</h1>
                <PrimaryButton type="button" @click="openCreate">
                    <Icon name="plus" class="me-1" /> {{ t('doc_add_type') }}
                </PrimaryButton>
            </div>
        </template>

        <div class="mx-auto max-w-5xl">
            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('doc_required') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('doc_validity') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('doc_max_age') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('doc_records') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="type in documentTypes" :key="type.id" :class="{ 'opacity-60': !type.is_active }">
                            <td class="px-4 py-3">
                                <p class="text-sm font-medium text-slate-900 dark:text-slate-100">
                                    {{ type.localized_name || type.name }}
                                    <span v-if="!type.is_active" class="ms-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500 dark:bg-slate-800">{{ t('inactive') }}</span>
                                </p>
                                <p class="font-mono text-xs text-slate-400">{{ type.code }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <Badge v-if="type.is_required" :label="t('doc_required')" color="primary" />
                                <Badge v-else :label="t('doc_optional')" color="slate" />
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ validityLabels[type.validity] || type.validity }}</td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                {{ type.max_age ? t('doc_up_to_age', { age: type.max_age }) : t('doc_all_ages') }}
                            </td>
                            <td class="px-4 py-3 text-end text-sm text-slate-600 dark:text-slate-300">{{ type.player_documents_count }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-end">
                                <button type="button" class="text-sm text-slate-500 hover:text-slate-700 dark:hover:text-slate-200" @click="openEdit(type)">{{ t('edit') }}</button>
                                <button type="button" class="ms-3 text-sm text-slate-500 hover:text-slate-700 dark:hover:text-slate-200" @click="toggleActive(type)">
                                    {{ type.is_active ? t('doc_deactivate') : t('doc_activate') }}
                                </button>
                                <!-- Refused server-side too while any player has a record of this type. -->
                                <button v-if="type.player_documents_count === 0" type="button" class="ms-3 text-sm text-rose-500 hover:text-rose-700" @click="deleteId = type.id">{{ t('delete') }}</button>
                            </td>
                        </tr>
                        <tr v-if="!documentTypes.length">
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Modal :show="editing !== null" max-width="lg" @close="editing = null">
            <form class="space-y-4 p-6" @submit.prevent="save">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ editing ? t('doc_edit_type') : t('doc_add_type') }}</h3>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <InputLabel :value="t('name')" />
                        <TextInput v-model="form.name" class="mt-1 w-full" required />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="العربية" />
                        <TextInput v-model="form.name_ar" class="mt-1 w-full" dir="rtl" />
                    </div>
                    <div>
                        <InputLabel value="Français" />
                        <TextInput v-model="form.name_fr" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel value="English" />
                        <TextInput v-model="form.name_en" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel :value="t('doc_validity')" />
                        <select v-model="form.validity" :class="inputClass">
                            <option v-for="(label, value) in validityLabels" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <InputError :message="form.errors.validity" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('doc_max_age')" />
                        <input v-model="form.max_age" type="number" min="1" max="99" :class="inputClass" />
                        <InputError :message="form.errors.max_age" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('sort_order')" />
                        <input v-model="form.sort_order" type="number" min="0" :class="inputClass" />
                        <InputError :message="form.errors.sort_order" class="mt-1" />
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 sm:col-span-2">{{ t('doc_max_age_hint') }}</p>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input v-model="form.is_required" type="checkbox" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                        {{ t('doc_required') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                        {{ t('active') }}
                    </label>
                </div>

                <div class="flex justify-end gap-3">
                    <SecondaryButton type="button" @click="editing = null">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">{{ t('save') }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
