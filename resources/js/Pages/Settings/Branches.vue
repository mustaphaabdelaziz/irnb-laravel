<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';

const { t } = useI18n();

const props = defineProps({
    branches: Array,
    players: { type: Array, default: () => [] },
    mainBranchId: { type: Number, default: null },
    canConvertIds: { type: Boolean, default: false },
});


const playersById = computed(() => Object.fromEntries(props.players.map((p) => [p.id, p])));

const deleteId = ref(null);

const form = useForm({ name: '', code: '', name_ar: '', name_fr: '', name_en: '', description: '', logo: null });
const addLogoInput = ref(null);

function addBranch() {
    form.post(route('branches.store'), {
        forceFormData: true,
        onSuccess: () => {
            form.reset();
            if (addLogoInput.value) addLogoInput.value.value = '';
        },
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
const membersJoinDate = ref('');
// The date error of the last save, kept here so reopening the dialog starts clean.
const membersDateError = ref('');

function openMembers(branch) {
    membersBranch.value = branch;
    memberIds.value = (branch.players || []).map((p) => p.id);
    memberPick.value = '';
    membersJoinDate.value = '';
    membersDateError.value = '';
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
    membersDateError.value = '';
    router.post(route('branches.players.sync', membersBranch.value.id), { player_ids: memberIds.value, join_date: membersJoinDate.value }, {
        preserveScroll: true,
        onSuccess: () => { membersBranch.value = null; },
        onError: (e) => { membersDateError.value = e.join_date || ''; },
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
                        <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('branch_code') }}</label>
                        <TextInput v-model="form.code" class="mt-1 w-full uppercase" maxlength="6" dir="ltr" />
                        <InputError :message="form.errors.code" class="mt-1" />
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
                    <div>
                        <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('logo') }}</label>
                        <input ref="addLogoInput" type="file" accept="image/*" class="mt-1 block w-full text-xs" @change="form.logo = $event.target.files[0] || null" />
                        <InputError :message="form.errors.logo" class="mt-1" />
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
                                <div class="flex items-center gap-2">
                                    <img v-if="branch.logo_url" :src="branch.logo_url" alt="" class="h-8 w-8 shrink-0 rounded object-contain" />
                                    <span v-else class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ (branch.localized_name || branch.name || '?').charAt(0) }}</span>
                                    <Link :href="route('branches.show', branch.id)" class="text-sm font-medium text-slate-900 hover:underline dark:text-slate-100">{{ branch.localized_name || branch.name }}</Link>
                                    <span v-if="branch.id === mainBranchId" class="ms-2 rounded bg-primary-50 px-1.5 py-0.5 text-xs text-primary-700 dark:bg-primary-900/30 dark:text-primary-200">{{ t('main_branch') }}</span>
                                    <span class="ms-2 font-mono text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ branch.code }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ branch.players_count }}</td>
                            <td class="px-4 py-3 text-end">
                                <div class="flex justify-end gap-2">
                                    <IconButton v-if="branch.id !== mainBranchId" icon="flag" :label="t('set_main_branch')" plain size="sm" @click="router.post(route('branches.main', branch.id), {}, { preserveScroll: true })" />
                                    <IconButton icon="players" :label="t('members')" variant="primary" plain size="sm" @click="openMembers(branch)" />
                                    <IconButton icon="pencil" :label="t('edit')" plain size="sm" :href="route('branches.show', branch.id)" />
                                    <IconButton icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = branch.id" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!branches?.length">
                            <td colspan="3" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Link v-if="canConvertIds" :href="route('membership-ids.index')" class="inline-block text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">{{ t('membership_ids_convert_title') }}</Link>
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
                    <div class="mt-4">
                        <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('join_date') }}</label>
                        <input v-model="membersJoinDate" type="date" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-900 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('join_date_for_new_members') }}</p>
                        <InputError :message="membersDateError" class="mt-1" />
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <span v-for="id in memberIds" :key="id" class="inline-flex items-center gap-1.5 rounded-full bg-primary-50 px-3 py-1 text-sm text-primary-700 dark:bg-primary-900/30 dark:text-primary-200">
                            {{ memberName(id) }}
                            <button type="button" @click="removeMember(id)" class="text-primary-400 dark:text-primary-300 hover:text-rose-600 dark:hover:text-rose-400">×</button>
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
