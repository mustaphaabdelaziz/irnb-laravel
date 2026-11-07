<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t, locale } = useI18n();

const props = defineProps({
    documentTypes: { type: Array, default: () => [] },
    // [{ id, name }]
    branches: { type: Array, default: () => [] },
    // { [typeId]: { [branchId]: count } }: records that stop counting when a branch is unticked.
    recordsByBranch: { type: Object, default: () => ({}) },
});

// Every field the server accepts, with the value a new type starts from.
const FIELDS = {
    name: '',
    name_ar: '',
    name_fr: '',
    name_en: '',
    is_required: false,
    required_for_matches: false,
    validity: 'none',
    max_age: '',
    copies: 1,
    sort_order: 0,
    is_active: true,
    branch_ids: [],
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

const branchName = (id) => props.branches.find((branch) => branch.id === id)?.name ?? `#${id}`;

// Branches no active type is ticked for: a new branch starts with none.
const branchesWithoutDocuments = computed(() => props.branches.filter(
    (branch) => !props.documentTypes.some((type) => type.is_active && (type.branch_ids ?? []).includes(branch.id)),
));
const bannerNames = computed(() => branchesWithoutDocuments.value.map((branch) => branch.name).join(locale.value === 'ar' ? '، ' : ', '));

// Unticking a branch that holds records: say how many stop counting before saving.
const untickWarning = ref('');

function untickedWithRecords() {
    const type = props.documentTypes.find((row) => row.id === editing.value);
    if (!type) return [];
    const counts = props.recordsByBranch[type.id] ?? {};
    return (type.branch_ids ?? [])
        .filter((id) => !form.branch_ids.includes(id) && (counts[id] ?? 0) > 0)
        .map((id) => t('doc_untick_warning', { count: counts[id], branch: branchName(id) }));
}

const inputClass = 'mt-1 w-full rounded-lg border-slate-300 text-slate-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-500/40 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100';

// The full payload for a type (or for a blank one): the server expects every
// field on update, and an empty age box means "every age".
function payloadOf(type) {
    return Object.fromEntries(Object.keys(FIELDS).map((key) => {
        if (key === 'max_age') return [key, type.max_age ?? ''];
        if (key === 'branch_ids') return [key, [...(type.branch_ids ?? [])]];
        return [key, type[key] ?? FIELDS[key]];
    }));
}

function openCreate() {
    Object.assign(form, payloadOf(FIELDS));
    // A new type starts required in every branch (owner, 2026-11-07).
    form.branch_ids = props.branches.map((branch) => branch.id);
    form.clearErrors();
    editing.value = 0;
}

function openEdit(type) {
    Object.assign(form, payloadOf(type));
    form.clearErrors();
    editing.value = type.id;
}

function save(confirmed = false) {
    if (editing.value && !confirmed) {
        const warnings = untickedWithRecords();
        if (warnings.length) {
            untickWarning.value = warnings.join(' ');
            return;
        }
    }
    untickWarning.value = '';

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
                <IconButton icon="plus" :label="t('doc_add_type')" variant="primary" @click="openCreate" />
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-4">
            <p v-if="branchesWithoutDocuments.length" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30">
                {{ t('doc_branches_without_documents', { names: bannerNames }) }}
            </p>
            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('doc_branches') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('doc_required') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('doc_validity') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('doc_max_age') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('doc_copies') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('doc_records') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="type in documentTypes" :key="type.id" :class="{ 'opacity-60': !type.is_active }">
                            <td class="px-4 py-3">
                                <p class="text-sm font-medium text-slate-900 dark:text-slate-100">
                                    {{ type.localized_name || type.name }}
                                    <span v-if="!type.is_active" class="ms-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('inactive') }}</span>
                                </p>
                                <p class="font-mono text-xs text-slate-400">{{ type.code }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex max-w-xs flex-wrap gap-1">
                                    <Badge v-for="id in type.branch_ids" :key="id" :label="branchName(id)" color="primary" />
                                    <Badge v-if="!type.branch_ids?.length" :label="t('doc_no_branch')" color="slate" />
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <Badge v-if="type.is_required" :label="t('doc_required')" color="primary" />
                                <Badge v-else :label="t('doc_optional')" color="slate" />
                                <Badge v-if="type.required_for_matches" :label="t('doc_required_for_matches')" color="amber" />
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ validityLabels[type.validity] || type.validity }}</td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                {{ type.max_age ? t('doc_up_to_age', { age: type.max_age }) : t('doc_all_ages') }}
                            </td>
                            <td class="px-4 py-3 text-end text-sm text-slate-600 dark:text-slate-300">{{ type.copies }}</td>
                            <td class="px-4 py-3 text-end text-sm text-slate-600 dark:text-slate-300">{{ type.player_documents_count }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-end">
                                <div class="flex justify-end gap-1">
                                    <IconButton icon="pencil" :label="t('edit')" plain size="sm" @click="openEdit(type)" />
                                    <IconButton
                                        :icon="type.is_active ? 'archive' : 'restore'"
                                        :label="type.is_active ? t('doc_deactivate') : t('doc_activate')"
                                        :variant="type.is_active ? 'neutral' : 'success'"
                                        plain
                                        size="sm"
                                        @click="toggleActive(type)"
                                    />
                                    <!-- Refused server-side too while any player has a record of this type. -->
                                    <IconButton v-if="type.player_documents_count === 0" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = type.id" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!documentTypes.length">
                            <td colspan="8" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Modal :show="editing !== null" max-width="lg" @close="editing = null">
            <form class="space-y-4 p-6" @submit.prevent="save()">
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
                        <InputLabel :value="t('doc_copies')" />
                        <input v-model="form.copies" type="number" min="1" max="99" :class="inputClass" />
                        <InputError :message="form.errors.copies" class="mt-1" />
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('doc_copies_raise_hint') }}</p>
                    </div>
                    <div>
                        <InputLabel :value="t('sort_order')" />
                        <input v-model="form.sort_order" type="number" min="0" :class="inputClass" />
                        <InputError :message="form.errors.sort_order" class="mt-1" />
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 sm:col-span-2">{{ t('doc_max_age_hint') }}</p>
                    <fieldset v-if="branches.length" class="sm:col-span-2">
                        <legend class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('doc_branches') }}</legend>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('doc_branches_hint') }}</p>
                        <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <label v-for="branch in branches" :key="branch.id" class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                <input v-model="form.branch_ids" :value="branch.id" type="checkbox" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                                {{ branch.name }}
                            </label>
                        </div>
                        <InputError :message="form.errors.branch_ids" class="mt-1" />
                    </fieldset>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input v-model="form.is_required" type="checkbox" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                        {{ t('doc_required') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input v-model="form.required_for_matches" type="checkbox" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                        {{ t('doc_required_for_matches') }}
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
        <ConfirmModal :show="!!untickWarning" :message="untickWarning" @confirm="save(true)" @cancel="untickWarning = ''" />
    </AuthenticatedLayout>
</template>
