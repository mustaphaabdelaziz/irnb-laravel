<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { useCan } from '@/Composables/useCan';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({
    jobs: Array,
});

const editingId = ref(null);
const deleteId = ref(null);

const form = useForm({ name: '', name_ar: '', name_fr: '', name_en: '', description: '' });
const editForm = useForm({ name: '', name_ar: '', name_fr: '', name_en: '', description: '' });

function usageCount(job) {
    return (job.players_count || 0) + (job.users_count || 0);
}

function addJob() {
    form.post(route('jobs.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function startEdit(job) {
    editForm.clearErrors();
    editingId.value = job.id;
    editForm.name = job.name;
    editForm.name_ar = job.name_ar || '';
    editForm.name_fr = job.name_fr || '';
    editForm.name_en = job.name_en || '';
    editForm.description = job.description || '';
}

function saveEdit(id) {
    editForm.put(route('jobs.update', id), {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
    });
}

function destroy() {
    router.delete(route('jobs.destroy', deleteId.value), {
        preserveScroll: true,
        onSuccess: () => { deleteId.value = null; },
    });
}

// --- Merge (same-job-different-spelling cleanup) ---
// Kept separate from the delete ConfirmModal because merging needs a target
// job picked first; the picker lives inline in the row, and only the
// destructive step itself (deleting the source job) goes through a confirm.
const mergeTarget = ref({});
const mergeConfirmId = ref(null);
const mergeBusy = ref(false);
const mergeErrorId = ref(null);
const mergeError = ref('');

function otherJobs(job) {
    return props.jobs.filter((j) => j.id !== job.id);
}

function askMerge(job) {
    if (!mergeTarget.value[job.id]) return;
    mergeConfirmId.value = job.id;
}

function cancelMerge() {
    mergeConfirmId.value = null;
}

function doMerge() {
    const jobId = mergeConfirmId.value;
    const into = mergeTarget.value[jobId];
    if (!into) {
        mergeConfirmId.value = null;
        return;
    }

    mergeBusy.value = true;
    router.post(route('jobs.merge', jobId), { into }, {
        preserveScroll: true,
        onSuccess: () => {
            mergeTarget.value = { ...mergeTarget.value, [jobId]: null };
            mergeErrorId.value = null;
            mergeError.value = '';
        },
        onError: (errors) => {
            mergeErrorId.value = jobId;
            mergeError.value = errors.into || t('save_failed');
        },
        onFinish: () => {
            mergeBusy.value = false;
            mergeConfirmId.value = null;
        },
    });
}
</script>

<template>
    <Head :title="t('jobs')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('jobs') }}</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <!-- Add form -->
            <form @submit.prevent="addJob" class="space-y-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
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
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('description') }}</th>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('members') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="job in jobs" :key="job.id">
                            <td class="px-4 py-3 align-top">
                                <div v-if="editingId === job.id" class="space-y-1.5">
                                    <TextInput v-model="editForm.name" class="w-full" :placeholder="t('name')" />
                                    <TextInput v-model="editForm.name_ar" class="w-full" placeholder="العربية" />
                                    <TextInput v-model="editForm.name_fr" class="w-full" placeholder="Français" />
                                    <TextInput v-model="editForm.name_en" class="w-full" placeholder="English" />
                                    <InputError :message="editForm.errors.name" class="mt-1" />
                                </div>
                                <span v-else class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ job.localized_name || job.name }}</span>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <TextInput v-if="editingId === job.id" v-model="editForm.description" class="w-full" />
                                <span v-else class="text-sm text-slate-600 dark:text-slate-300">{{ job.description || '-' }}</span>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <span class="text-sm text-slate-600 dark:text-slate-300">{{ t('in_use_by', { count: usageCount(job) }) }}</span>
                            </td>
                            <td class="px-4 py-3 text-end align-top">
                                <div v-if="editingId === job.id" class="flex justify-end gap-2">
                                    <button @click="saveEdit(job.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('save') }}</button>
                                    <button @click="editingId = null" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('cancel') }}</button>
                                </div>
                                <div v-else class="flex flex-col items-end gap-2">
                                    <div class="flex justify-end gap-2">
                                        <button @click="startEdit(job)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('edit') }}</button>
                                        <button
                                            @click="deleteId = job.id"
                                            :disabled="usageCount(job) > 0"
                                            :title="usageCount(job) > 0 ? t('in_use_by', { count: usageCount(job) }) : ''"
                                            class="text-sm text-rose-500 hover:text-rose-700 disabled:cursor-not-allowed disabled:text-slate-300 dark:disabled:text-slate-600"
                                        >{{ t('delete') }}</button>
                                    </div>
                                    <!-- Merging repoints every member to another job then deletes this one,
                                         so it needs the same delete permission destroy() needs. -->
                                    <div v-if="can('categories', 'delete') && otherJobs(job).length" class="flex items-center gap-1.5">
                                        <select v-model="mergeTarget[job.id]" class="rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 py-1 text-xs shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                            <option :value="null">{{ t('merge_into') }}</option>
                                            <option v-for="other in otherJobs(job)" :key="other.id" :value="other.id">{{ other.localized_name || other.name }}</option>
                                        </select>
                                        <button
                                            type="button"
                                            @click="askMerge(job)"
                                            :disabled="!mergeTarget[job.id]"
                                            class="text-xs font-semibold text-primary-600 hover:text-primary-700 disabled:cursor-not-allowed disabled:text-slate-300 dark:disabled:text-slate-600"
                                        >{{ t('merge') }}</button>
                                    </div>
                                    <p v-if="mergeErrorId === job.id && mergeError" class="max-w-[16rem] text-xs text-rose-600 dark:text-rose-400">{{ mergeError }}</p>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!jobs?.length">
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
        <ConfirmModal :show="!!mergeConfirmId" :title="t('merge')" :message="t('are_you_sure')" :confirm-label="t('merge')" :busy="mergeBusy" @confirm="doMerge" @cancel="cancelMerge" />
    </AuthenticatedLayout>
</template>
