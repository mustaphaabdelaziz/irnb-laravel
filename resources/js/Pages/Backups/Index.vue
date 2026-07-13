<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Icon from '@/Components/Icon.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref, computed } from 'vue';

const { t, locale } = useI18n();

const props = defineProps({
    settings: { type: Object, required: true },
    destinationWritable: { type: Boolean, required: true },
    backups: { type: Array, default: () => [] },

    // The outcome of the last restore. NOT a flash message — a restore relaunches the
    // app and the flash bag dies with the process before it is ever written (see
    // BackupSettings::recordRestore()). Rendered below as a persistent, dismissible
    // banner: no auto-dismiss, no clipping, and the paths are selectable/copyable
    // because they are absolute Windows paths the user may have to act on.
    lastRestore: { type: Object, default: null },
});

const settingsForm = useForm({
    enabled: props.settings.enabled,
    frequency: props.settings.frequency,
    retention: props.settings.retention,
});

const restoreForm = useForm({ name: '', confirmation: '' });

const restoring = ref(null);
const deleting = ref(null);
const backingUp = ref(false);
const copiedPath = ref(null);

const lastBackup = computed(() =>
    props.settings.last_run_at ? formatDate(props.settings.last_run_at) : t('never'));

// failed_after_swap: the database is already swapped — the most severe outcome, and
// the only one that always carries a snapshot path (the user's only way back).
// success + leftover_media: it worked, but a full duplicate of every photo is still
// on disk and nothing in the app will ever clean it up.
// failed: nothing was touched — reassuring, even though it is still an error.
const bannerTone = computed(() => {
    const outcome = props.lastRestore?.outcome;
    if (outcome === 'failed_after_swap' || outcome === 'failed') return 'danger';
    if (outcome === 'success' && props.lastRestore?.leftover_media) return 'warning';
    return 'success';
});

const toneClasses = {
    danger: 'bg-rose-50 text-rose-900 ring-1 ring-rose-300 dark:bg-rose-500/10 dark:text-rose-100 dark:ring-rose-500/30',
    warning: 'bg-amber-50 text-amber-900 ring-1 ring-amber-300 dark:bg-amber-500/10 dark:text-amber-100 dark:ring-amber-500/30',
    success: 'bg-emerald-50 text-emerald-900 ring-1 ring-emerald-300 dark:bg-emerald-500/10 dark:text-emerald-100 dark:ring-emerald-500/30',
};
const toneIcons = { danger: 'alert', warning: 'alert', success: 'check' };

const bannerClass = computed(() => toneClasses[bannerTone.value]);
const bannerIcon = computed(() => toneIcons[bannerTone.value]);

function formatDate(iso) {
    return new Date(iso).toLocaleString(locale.value);
}

function formatSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    if (bytes < 1024 * 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    return `${(bytes / 1024 / 1024 / 1024).toFixed(2)} GB`;
}

// The path is already selectable (select-all + break-all), so a clipboard failure —
// the API can be unavailable in some webview contexts — just leaves the user to copy
// it by hand instead of silently doing nothing.
async function copyPath(text) {
    try {
        await navigator.clipboard.writeText(text);
        copiedPath.value = text;
        setTimeout(() => {
            if (copiedPath.value === text) copiedPath.value = null;
        }, 2000);
    } catch {
        //
    }
}

function dismissRestore() {
    router.delete(route('backups.last-restore.dismiss'), { preserveScroll: true });
}

function chooseFolder() {
    router.post(route('backups.folder'), {}, { preserveScroll: true });
}

function openFolder(name = '') {
    router.post(route('backups.reveal'), { name }, { preserveScroll: true });
}

function saveSettings() {
    settingsForm.put(route('backups.settings'), { preserveScroll: true });
}

function backupNow() {
    backingUp.value = true;
    router.post(route('backups.store'), {}, {
        preserveScroll: true,
        onFinish: () => { backingUp.value = false; },
    });
}

function askRestore(backup) {
    restoring.value = backup;
    restoreForm.reset();
    restoreForm.clearErrors();
    restoreForm.name = backup.name;
}

function confirmRestore() {
    restoreForm.post(route('backups.restore'), {
        preserveScroll: true,
        onSuccess: () => { restoring.value = null; },
    });
}

function destroy() {
    router.delete(route('backups.destroy', deleting.value.name), {
        preserveScroll: true,
        onSuccess: () => { deleting.value = null; },
    });
}
</script>

<template>
    <Head :title="t('backup')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('backup') }}</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <!-- Last restore outcome: persistent, not a flash — see the prop doc above. -->
            <div v-if="lastRestore" class="flex items-start gap-3 rounded-2xl p-5 shadow-sm" :class="bannerClass">
                <Icon :name="bannerIcon" class="mt-0.5 shrink-0 text-xl" />

                <div class="min-w-0 flex-1 space-y-3">
                    <p class="whitespace-pre-line break-words text-sm font-medium">{{ lastRestore.message }}</p>
                    <p v-if="lastRestore.at" class="text-xs opacity-80">{{ t('date') }}: {{ formatDate(lastRestore.at) }}</p>

                    <div v-if="lastRestore.snapshot">
                        <p class="text-xs font-semibold uppercase tracking-wide opacity-80">{{ t('snapshot') }}</p>
                        <div class="mt-1 flex flex-wrap items-start gap-2">
                            <code class="min-w-0 flex-1 select-all whitespace-pre-wrap break-all rounded-lg bg-white/70 px-3 py-2 text-xs dark:bg-black/20">{{ lastRestore.snapshot }}</code>
                            <button type="button" @click="copyPath(lastRestore.snapshot)" class="shrink-0 text-xs font-semibold underline underline-offset-2 opacity-80 hover:opacity-100">
                                {{ copiedPath === lastRestore.snapshot ? t('copied') : t('copy') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="lastRestore.leftover_media">
                        <p class="text-xs font-semibold uppercase tracking-wide opacity-80">{{ t('leftover_media') }}</p>
                        <div class="mt-1 flex flex-wrap items-start gap-2">
                            <code class="min-w-0 flex-1 select-all whitespace-pre-wrap break-all rounded-lg bg-white/70 px-3 py-2 text-xs dark:bg-black/20">{{ lastRestore.leftover_media }}</code>
                            <button type="button" @click="copyPath(lastRestore.leftover_media)" class="shrink-0 text-xs font-semibold underline underline-offset-2 opacity-80 hover:opacity-100">
                                {{ copiedPath === lastRestore.leftover_media ? t('copied') : t('copy') }}
                            </button>
                        </div>
                    </div>
                </div>

                <button type="button" @click="dismissRestore" class="shrink-0 opacity-60 transition-opacity hover:opacity-100" :title="t('close')">
                    <Icon name="xcircle" class="text-lg" />
                </button>
            </div>

            <!-- Destination -->
            <section class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('backup_destination') }}</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('backup_destination_hint') }}</p>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <code v-if="settings.destination" class="min-w-0 flex-1 select-all whitespace-pre-wrap break-all rounded-lg bg-slate-50 dark:bg-slate-950 px-3 py-2 text-xs text-slate-700 dark:text-slate-300">
                        {{ settings.destination }}
                    </code>
                    <p v-else class="flex-1 text-sm text-slate-500 dark:text-slate-400">{{ t('backup_no_destination') }}</p>

                    <SecondaryButton @click="chooseFolder">{{ t('choose_folder') }}</SecondaryButton>
                    <SecondaryButton v-if="settings.destination" @click="openFolder()">{{ t('open_folder') }}</SecondaryButton>
                </div>

                <p v-if="settings.destination && !destinationWritable" class="mt-3 rounded-lg bg-rose-50 dark:bg-rose-950 px-3 py-2 text-sm text-rose-700 dark:text-rose-300">
                    {{ t('backup_destination_unavailable') }}
                </p>
            </section>

            <!-- Automatic backup -->
            <section class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('automatic_backup') }}</h2>

                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input type="checkbox" v-model="settingsForm.enabled" class="rounded border-slate-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800" />
                        {{ t('automatic_backup') }}
                    </label>

                    <div>
                        <label class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ t('frequency') }}</label>
                        <select v-model="settingsForm.frequency" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="every_launch">{{ t('every_launch') }}</option>
                            <option value="daily">{{ t('daily') }}</option>
                            <option value="weekly">{{ t('weekly') }}</option>
                        </select>
                        <InputError :message="settingsForm.errors.frequency" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ t('keep_last_backups') }}</label>
                        <TextInput v-model="settingsForm.retention" type="number" min="0" max="365" class="mt-1 w-full" />
                        <p class="mt-1 text-xs text-slate-400">{{ t('keep_last_backups_hint') }}</p>
                        <InputError :message="settingsForm.errors.retention" class="mt-1" />
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('last_backup') }}: {{ lastBackup }}</p>
                    <PrimaryButton :disabled="settingsForm.processing" @click="saveSettings">{{ t('save') }}</PrimaryButton>
                </div>
            </section>

            <!-- Backups -->
            <section class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="flex items-center justify-between p-5">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('backups') }}</h2>
                    <PrimaryButton :disabled="backingUp || !destinationWritable" @click="backupNow">{{ t('backup_now') }}</PrimaryButton>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('date') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('size') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="backup in backups" :key="backup.name">
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-900 dark:text-slate-100">{{ formatDate(backup.created_at) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-500 dark:text-slate-400">{{ formatSize(backup.bytes) }}</td>
                                <td class="px-4 py-3 text-end">
                                    <div class="flex justify-end gap-3">
                                        <button @click="askRestore(backup)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('restore') }}</button>
                                        <button @click="openFolder(backup.name)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('open_folder') }}</button>
                                        <button @click="deleting = backup" class="text-sm text-rose-500 hover:text-rose-700">{{ t('delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!backups?.length">
                                <td colspan="3" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_backups') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- Restore: typed confirmation, because this is the one irreversible action in the app -->
        <Modal :show="!!restoring" @close="restoring = null">
            <div class="p-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('restore') }}</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ t('backup_restore_warning') }}</p>
                <p class="mt-2 break-all text-sm font-medium text-slate-900 dark:text-slate-100">{{ restoring?.name }}</p>

                <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">{{ t('backup_type_restore_to_confirm') }}</p>
                <TextInput v-model="restoreForm.confirmation" class="mt-1 w-full" placeholder="RESTORE" />
                <InputError :message="restoreForm.errors.confirmation" class="mt-1" />

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="restoring = null">{{ t('cancel') }}</SecondaryButton>
                    <DangerButton :disabled="restoreForm.processing" @click="confirmRestore">{{ t('restore') }}</DangerButton>
                </div>
            </div>
        </Modal>

        <ConfirmModal :show="!!deleting" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleting = null" />
    </AuthenticatedLayout>
</template>
