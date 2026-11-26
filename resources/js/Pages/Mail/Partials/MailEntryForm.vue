<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { localToday } from '@/lib/localDate';

const { t } = useI18n();
const props = defineProps({
    entry: { type: Object, default: null },
    // A new entry's direction (the tab the form was opened from).
    direction: { type: String, default: 'out' },
    // Edit: the fields the entry may still change (MailEntry::editableFields()).
    editable: { type: Array, default: () => [] },
    // Earlier correspondents per direction, the same direction first.
    correspondents: { type: Object, default: () => ({ out: [], in: [] }) },
});
const isEdit = !!props.entry;
const day = (value) => (value ? String(value).slice(0, 10) : '');

const form = useForm({
    direction: props.entry?.direction ?? props.direction,
    entry_date: day(props.entry?.entry_date) || localToday(),
    correspondent: props.entry?.correspondent ?? '',
    address: props.entry?.address ?? '',
    subject: props.entry?.subject ?? '',
    body: props.entry?.body ?? '',
    sender_reference: props.entry?.sender_reference ?? '',
    received_date: day(props.entry?.received_date),
    notes: props.entry?.notes ?? '',
    scan: null,
});

// A new entry sets everything; an existing one only what the server allows.
const may = (field) => !isEdit || props.editable.includes(field);
const outgoing = computed(() => form.direction === 'out');
const suggestions = computed(() => props.correspondents?.[form.direction] ?? []);
const hint = computed(() => {
    if (!isEdit) return null;
    if (props.entry.is_legacy) return t('mreg.legacy_hint');
    if (props.entry.source_type) return t('mreg.from_print_hint');
    return null;
});
const field = 'mt-1 w-full rounded-lg border-slate-300 shadow-sm disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900';

function submit() {
    form.transform((data) => {
        const sent = { ...data };
        if (isEdit) {
            // Direction and number never change; the file needs a POST spoofed as PUT.
            delete sent.direction;
            sent._method = 'put';
        }
        if (!sent.scan) delete sent.scan;
        return sent;
    }).post(isEdit ? route('mail.update', props.entry.id) : route('mail.store'), { forceFormData: true });
}
</script>

<template>
    <form @submit.prevent="submit" class="mx-auto max-w-4xl space-y-6">
        <p v-if="hint" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ hint }}</p>

        <div class="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <fieldset v-if="!isEdit">
                <legend class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ t('mreg.direction') }}</legend>
                <div class="mt-2 flex flex-wrap gap-4">
                    <label v-for="d in ['out', 'in']" :key="d" class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input v-model="form.direction" type="radio" :value="d" class="border-slate-300 text-primary-600 focus:ring-primary-500" />
                        {{ t(d === 'out' ? 'mreg.outgoing' : 'mreg.incoming') }}
                    </label>
                </div>
                <InputError :message="form.errors.direction" class="mt-1" />
            </fieldset>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <InputLabel :value="t('mreg.entry_date')" required />
                    <TextInput v-model="form.entry_date" type="date" required :disabled="!may('entry_date')" class="mt-1 w-full" />
                    <InputError :message="form.errors.entry_date" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <InputLabel :value="t(outgoing ? 'mreg.recipient' : 'mreg.sender')" required />
                    <TextInput v-model="form.correspondent" list="mail-correspondents" maxlength="200" :required="may('correspondent')" :disabled="!may('correspondent')" class="mt-1 w-full" />
                    <datalist id="mail-correspondents">
                        <option v-for="name in suggestions" :key="name" :value="name" />
                    </datalist>
                    <InputError :message="form.errors.correspondent" class="mt-1" />
                </div>
                <div class="sm:col-span-3">
                    <InputLabel :value="t('address')" />
                    <TextInput v-model="form.address" maxlength="255" :disabled="!may('address')" class="mt-1 w-full" />
                    <InputError :message="form.errors.address" class="mt-1" />
                </div>
                <div class="sm:col-span-3">
                    <InputLabel :value="t('mreg.subject')" required />
                    <TextInput v-model="form.subject" maxlength="255" :required="may('subject')" :disabled="!may('subject')" class="mt-1 w-full" />
                    <InputError :message="form.errors.subject" class="mt-1" />
                </div>
                <template v-if="!outgoing">
                    <div>
                        <InputLabel :value="t('mreg.sender_reference')" />
                        <TextInput v-model="form.sender_reference" maxlength="100" :disabled="!may('sender_reference')" class="mt-1 w-full" />
                        <InputError :message="form.errors.sender_reference" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="t('mreg.received_date')" />
                        <TextInput v-model="form.received_date" type="date" :disabled="!may('received_date')" class="mt-1 w-full" />
                        <InputError :message="form.errors.received_date" class="mt-1" />
                    </div>
                </template>
                <div v-if="outgoing" class="sm:col-span-3">
                    <InputLabel :value="t('mreg.body')" />
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('mreg.body_hint') }}</p>
                    <textarea v-model="form.body" rows="10" maxlength="20000" :disabled="!may('body')" :class="field"></textarea>
                    <InputError :message="form.errors.body" class="mt-1" />
                </div>
                <div class="sm:col-span-3">
                    <InputLabel :value="t('notes')" />
                    <textarea v-model="form.notes" rows="2" maxlength="5000" :disabled="!may('notes')" :class="field"></textarea>
                    <InputError :message="form.errors.notes" class="mt-1" />
                </div>
                <div class="sm:col-span-3">
                    <InputLabel :value="t('mreg.scan')" />
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('mreg.scan_hint') }}</p>
                    <a v-if="entry?.has_scan" :href="route('mail.scan', entry.id)" target="_blank" class="mt-1 inline-block text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">{{ entry.scan_name || t('mreg.scan_open') }} ↗</a>
                    <input type="file" accept="application/pdf,image/jpeg,image/png,image/webp" @change="form.scan = $event.target.files[0] ?? null"
                        class="mt-1 w-full text-sm text-slate-600 dark:text-slate-300 file:me-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100 dark:file:bg-primary-500/10 dark:file:text-primary-300 dark:hover:file:bg-primary-500/15" />
                    <InputError :message="form.errors.scan" class="mt-1" />
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="isEdit ? route('mail.show', entry.id) : route('mail.index', { direction: form.direction })"><SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton></Link>
            <PrimaryButton :disabled="form.processing">{{ isEdit ? t('save_changes') : t('save') }}</PrimaryButton>
        </div>
    </form>
</template>
