<script setup>
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useCan } from '@/Composables/useCan';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    playerId: { type: Number, required: true },
    // DocumentChecklist::for(): { items, missing_count, expiring_count }
    checklist: { type: Object, required: true },
});

const { t } = useI18n();
const { can } = useCan();

// Image types make phone browsers offer the camera. No `capture` attribute, so
// the gallery and the file picker stay available too.
const ACCEPT = 'application/pdf,image/jpeg,image/png,image/webp,.pdf,.jpg,.jpeg,.png,.webp';

const STATE_COLORS = {
    received_scanned: 'emerald',
    received_paper: 'blue',
    expires_soon: 'amber',
    expired: 'rose',
    missing: 'rose',
    not_required: 'slate',
};

const stateLabels = computed(() => ({
    received_scanned: t('doc_state_received_scanned'),
    received_paper: t('doc_state_received_paper'),
    expires_soon: t('doc_state_expires_soon'),
    expired: t('doc_state_expired'),
    missing: t('doc_state_missing'),
    not_required: t('doc_state_not_required'),
}));

const reasonLabels = computed(() => ({
    exempt: t('doc_reason_exempt'),
    optional: t('doc_optional'),
    age: t('doc_reason_age'),
}));

const items = computed(() => props.checklist?.items ?? []);

const buttonPrimary = 'inline-flex items-center gap-1 rounded-md bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700';
const buttonOutline = 'inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-300 hover:bg-primary-50 dark:text-primary-300 dark:ring-primary-700 dark:hover:bg-primary-900/30';
const buttonQuiet = 'inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800';
const fileInputClass = 'mt-1 block w-full text-xs text-slate-500 file:me-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-primary-700 hover:file:bg-primary-100 dark:text-slate-400 dark:file:bg-primary-500/10 dark:file:text-primary-300';

function formatDate(value) {
    return value ? new Date(`${value}T00:00:00`).toLocaleDateString() : '—';
}

function formatSize(bytes) {
    return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

// Local date, not UTC: toISOString() alone would say "yesterday" before 1 a.m. in Algiers.
function todayIso() {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    return now.toISOString().slice(0, 10);
}

// Laravel reports array uploads as files.0, files.1, ...
function fileErrors(form) {
    return Object.entries(form.errors)
        .filter(([key]) => key === 'files' || key.startsWith('files.'))
        .map(([, message]) => message);
}

const canReceive = (item) => !item.type.is_photo && !item.document && item.type.is_active && can('documents', 'add');
const canRenew = (item) => !item.type.is_photo && item.document?.state === 'received' && can('documents', 'edit');
const canUpload = (item) => !item.type.is_photo && item.document?.state === 'received' && can('documents', 'add');
const canExempt = (item) => item.type.is_active
    && item.document?.state !== 'exempt'
    && !(item.type.is_photo && item.state === 'received_scanned')
    && can('documents', 'edit');
const canUnexempt = (item) => item.document?.state === 'exempt' && can('documents', 'edit');

const fileUrl = (file) => route('players.documents.files.show', { player: props.playerId, file: file.id });
const downloadUrl = (file) => route('players.documents.files.download', { player: props.playerId, file: file.id });

// --- Mark received / renew (same form) ---
const receiving = ref(null);
const receiveForm = useForm({ document_type_id: null, received_at: '', valid_until: '', notes: '', files: [] });

function openReceive(item) {
    receiveForm.clearErrors();
    receiveForm.document_type_id = item.type.id;
    receiveForm.received_at = todayIso();
    receiveForm.valid_until = '';
    receiveForm.notes = item.document?.notes ?? '';
    receiveForm.files = [];
    receiving.value = item;
}

function submitReceive() {
    const item = receiving.value;
    const options = {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => { receiving.value = null; },
    };

    if (item.document) {
        // PHP cannot read a multipart PUT: send it as POST with the method spoofed.
        receiveForm
            .transform((data) => ({ ...data, _method: 'put' }))
            .post(route('players.documents.update', { player: props.playerId, document: item.document.id }), options);
    } else {
        receiveForm
            .transform((data) => data)
            .post(route('players.documents.store', props.playerId), options);
    }
}

// --- Add files to a received document ---
const uploading = ref(null);
const uploadForm = useForm({ files: [] });

function openUpload(item) {
    uploadForm.clearErrors();
    uploadForm.files = [];
    uploading.value = item;
}

function submitUpload() {
    uploadForm.post(route('players.documents.files.store', { player: props.playerId, document: uploading.value.document.id }), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => { uploading.value = null; },
    });
}

// --- Exempt / undo ---
const exempting = ref(null);
const exemptForm = useForm({ document_type_id: null, reason: '' });

function openExempt(item) {
    exemptForm.clearErrors();
    exemptForm.document_type_id = item.type.id;
    exemptForm.reason = '';
    exempting.value = item;
}

function submitExempt() {
    exemptForm.post(route('players.documents.exempt', props.playerId), {
        preserveScroll: true,
        onSuccess: () => { exempting.value = null; },
    });
}

const unexempting = ref(null);

function confirmUnexempt() {
    const item = unexempting.value;
    unexempting.value = null;
    router.delete(route('players.documents.unexempt', { player: props.playerId, document: item.document.id }), { preserveScroll: true });
}

// --- Remove a file ---
const removingFile = ref(null);

function confirmRemoveFile() {
    const file = removingFile.value;
    removingFile.value = null;
    router.delete(route('players.documents.files.destroy', { player: props.playerId, file: file.id }), { preserveScroll: true });
}
</script>

<template>
    <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <h3 class="flex items-center gap-2 text-base font-semibold text-slate-900 dark:text-slate-100">
                <Icon name="folder" class="text-slate-400" /> {{ t('documents') }}
            </h3>
            <div class="flex flex-wrap items-center gap-2">
                <Badge v-if="checklist.missing_count > 0" :label="t('doc_missing_count', { count: checklist.missing_count })" color="rose" />
                <Badge v-else :label="t('doc_complete')" color="emerald" />
                <Badge v-if="checklist.expiring_count > 0" :label="t('doc_expiring_count', { count: checklist.expiring_count })" color="amber" />
            </div>
        </div>

        <div v-if="!items.length" class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_data') }}</div>

        <ul v-else class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="item in items" :key="item.type.id" class="px-5 py-4" :class="{ 'opacity-60': !item.type.is_active }">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-900 dark:text-slate-100">
                            {{ item.type.name }}
                            <span v-if="item.type.is_required" class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('doc_required') }}</span>
                            <span v-if="item.type.max_age" class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('doc_up_to_age', { age: item.type.max_age }) }}</span>
                            <span v-if="!item.type.is_active" class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ t('doc_inactive_type') }}</span>
                        </p>
                        <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                            <span v-if="item.type.is_photo">{{ t('doc_photo_hint') }}</span>
                            <span v-if="item.document?.received_at">{{ t('doc_received_on') }} {{ formatDate(item.document.received_at) }}</span>
                            <span v-if="item.document?.valid_until">{{ t('doc_valid_until') }} {{ formatDate(item.document.valid_until) }}</span>
                            <span v-if="item.document?.state === 'exempt' && item.document.exempt_reason">{{ t('doc_exempt_reason') }}: {{ item.document.exempt_reason }}</span>
                            <span v-if="item.document?.notes" class="italic">{{ item.document.notes }}</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge :label="stateLabels[item.state]" :color="STATE_COLORS[item.state]" dot />
                        <span v-if="item.state === 'not_required' && item.reason" class="text-xs text-slate-400">{{ reasonLabels[item.reason] }}</span>
                    </div>
                </div>

                <ul v-if="item.document?.files?.length" class="mt-2 space-y-1">
                    <li v-for="file in item.document.files" :key="file.id" class="flex flex-wrap items-center gap-2 text-xs">
                        <Icon name="document" class="text-primary-500" />
                        <a :href="fileUrl(file)" target="_blank" rel="noopener" class="min-w-0 max-w-xs truncate font-medium text-primary-600 hover:underline dark:text-primary-300">{{ file.original_name }}</a>
                        <span class="text-slate-400">{{ formatSize(file.size) }} · {{ formatDate(file.uploaded_at) }}</span>
                        <a :href="downloadUrl(file)" class="text-slate-500 hover:text-slate-700 dark:hover:text-slate-200" :title="t('doc_download')" :aria-label="t('doc_download')"><Icon name="download" /></a>
                        <button v-if="can('documents', 'delete')" type="button" class="text-slate-300 hover:text-rose-500" :title="t('remove')" :aria-label="t('remove')" @click="removingFile = file"><Icon name="xcircle" /></button>
                    </li>
                </ul>

                <div class="mt-3 flex flex-wrap gap-2">
                    <button v-if="canReceive(item)" type="button" :class="buttonPrimary" @click="openReceive(item)"><Icon name="check" /> {{ t('doc_mark_received') }}</button>
                    <button v-if="canRenew(item)" type="button" :class="buttonOutline" @click="openReceive(item)"><Icon name="refresh" /> {{ t('doc_renew') }}</button>
                    <button v-if="canUpload(item)" type="button" :class="buttonOutline" @click="openUpload(item)"><Icon name="upload" /> {{ t('doc_upload_files') }}</button>
                    <button v-if="canExempt(item)" type="button" :class="buttonQuiet" @click="openExempt(item)">{{ t('doc_exempt') }}</button>
                    <button v-if="canUnexempt(item)" type="button" :class="buttonQuiet" @click="unexempting = item">{{ t('doc_unexempt') }}</button>
                </div>
            </li>
        </ul>

        <!-- Mark received / renew -->
        <Modal :show="!!receiving" max-width="md" @close="receiving = null">
            <form v-if="receiving" class="space-y-4 p-6" @submit.prevent="submitReceive">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                    {{ receiving.document ? t('doc_renew') : t('doc_mark_received') }} · {{ receiving.type.name }}
                </h3>
                <div>
                    <InputLabel :value="t('doc_received_on')" />
                    <TextInput v-model="receiveForm.received_at" type="date" class="mt-1 w-full" :max="todayIso()" required />
                    <InputError :message="receiveForm.errors.received_at" class="mt-1" />
                </div>
                <div v-if="receiving.type.validity === 'date'">
                    <InputLabel :value="t('doc_valid_until')" />
                    <TextInput v-model="receiveForm.valid_until" type="date" class="mt-1 w-full" :min="receiveForm.received_at" required />
                    <InputError :message="receiveForm.errors.valid_until" class="mt-1" />
                </div>
                <p v-else-if="receiving.type.validity === 'season'" class="text-xs text-slate-500 dark:text-slate-400">{{ t('doc_validity_season_hint') }}</p>
                <div>
                    <InputLabel :value="t('notes')" />
                    <TextInput v-model="receiveForm.notes" class="mt-1 w-full" />
                    <InputError :message="receiveForm.errors.notes" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('doc_files')" />
                    <input type="file" multiple :accept="ACCEPT" :class="fileInputClass" @change="receiveForm.files = Array.from($event.target.files || [])" />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('doc_files_hint') }}</p>
                    <InputError v-for="(message, index) in fileErrors(receiveForm)" :key="index" :message="message" class="mt-1" />
                </div>
                <div class="flex justify-end gap-3">
                    <SecondaryButton type="button" @click="receiving = null">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="receiveForm.processing">{{ t('save') }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <!-- Add files -->
        <Modal :show="!!uploading" max-width="md" @close="uploading = null">
            <form v-if="uploading" class="space-y-4 p-6" @submit.prevent="submitUpload">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('doc_upload_files') }} · {{ uploading.type.name }}</h3>
                <div>
                    <input type="file" multiple :accept="ACCEPT" :class="fileInputClass" required @change="uploadForm.files = Array.from($event.target.files || [])" />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('doc_files_hint') }}</p>
                    <InputError v-for="(message, index) in fileErrors(uploadForm)" :key="index" :message="message" class="mt-1" />
                </div>
                <div class="flex justify-end gap-3">
                    <SecondaryButton type="button" @click="uploading = null">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="uploadForm.processing || !uploadForm.files.length">{{ t('upload') }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <!-- Exempt -->
        <Modal :show="!!exempting" max-width="md" @close="exempting = null">
            <form v-if="exempting" class="space-y-4 p-6" @submit.prevent="submitExempt">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('doc_exempt') }} · {{ exempting.type.name }}</h3>
                <div>
                    <InputLabel :value="t('doc_exempt_reason')" />
                    <TextInput v-model="exemptForm.reason" class="mt-1 w-full" required minlength="3" maxlength="255" />
                    <InputError :message="exemptForm.errors.reason" class="mt-1" />
                </div>
                <div class="flex justify-end gap-3">
                    <SecondaryButton type="button" @click="exempting = null">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="exemptForm.processing">{{ t('save') }}</PrimaryButton>
                </div>
            </form>
        </Modal>

        <ConfirmModal :show="!!unexempting" :message="t('doc_confirm_unexempt')" @confirm="confirmUnexempt" @cancel="unexempting = null" />
        <ConfirmModal :show="!!removingFile" :message="t('doc_confirm_remove_file')" @confirm="confirmRemoveFile" @cancel="removingFile = null" />
    </div>
</template>
