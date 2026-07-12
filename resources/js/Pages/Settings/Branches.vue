<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';

const { t } = useI18n();

const props = defineProps({
    branches: Array,
    players: { type: Array, default: () => [] },
});

const playersById = computed(() => Object.fromEntries(props.players.map((p) => [p.id, p])));

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

// --- Manage a branch's players directly from the branches menu ---
const membersBranch = ref(null);
const memberIds = ref([]);
const memberPick = ref('');
const savingMembers = ref(false);

function openMembers(branch) {
    membersBranch.value = branch;
    memberIds.value = (branch.players || []).map((p) => p.id);
    memberPick.value = '';
}

const memberOptions = computed(() => {
    const taken = new Set(memberIds.value);
    return props.players
        .filter((p) => !taken.has(p.id))
        .map((p) => ({ value: p.id, label: `${p.fullname || ''} — ${p.membership_id || ''}`.trim() }));
});

function addMember() {
    if (!memberPick.value) return;
    const id = Number(memberPick.value);
    if (!memberIds.value.includes(id)) memberIds.value.push(id);
    memberPick.value = '';
}

function removeMember(id) {
    memberIds.value = memberIds.value.filter((x) => x !== id);
}

function memberName(id) {
    const p = playersById.value[id];
    return p ? `${p.fullname || ''}${p.membership_id ? ' — ' + p.membership_id : ''}`.trim() : `#${id}`;
}

function saveMembers() {
    savingMembers.value = true;
    router.post(route('branches.players.sync', membersBranch.value.id), { player_ids: memberIds.value }, {
        preserveScroll: true,
        onSuccess: () => { membersBranch.value = null; },
        onFinish: () => { savingMembers.value = false; },
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
                                    <button @click="openMembers(branch)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('members') }}</button>
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

        <!-- Manage branch members modal -->
        <Teleport to="body">
            <div v-if="membersBranch" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="membersBranch = null">
                <div class="w-full max-w-lg rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('members') }} — {{ membersBranch.localized_name || membersBranch.name }}</h3>
                    <div class="mt-4">
                        <SearchableSelect v-model="memberPick" :options="memberOptions" :placeholder="t('add_member')" @update:modelValue="addMember" />
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <span v-for="id in memberIds" :key="id" class="inline-flex items-center gap-1.5 rounded-full bg-primary-50 px-3 py-1 text-sm text-primary-700 dark:bg-primary-900/30 dark:text-primary-200">
                            {{ memberName(id) }}
                            <button type="button" @click="removeMember(id)" class="text-primary-400 hover:text-rose-600">×</button>
                        </span>
                        <span v-if="!memberIds.length" class="text-sm text-slate-400">{{ t('no_results') }}</span>
                    </div>
                    <div class="mt-6 flex items-center justify-between gap-3">
                        <span class="text-xs text-slate-400">{{ memberIds.length }}</span>
                        <div class="flex gap-2">
                            <button type="button" @click="membersBranch = null" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                            <button type="button" @click="saveMembers" :disabled="savingMembers" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">{{ t('save') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
