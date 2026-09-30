<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { useCan } from '@/Composables/useCan';
import { parseDay } from '@/lib/attendanceCalendar';

/**
 * The profile's injury spells (InjurySpells on the server), built from the
 * marks, with the details staff added. attendance/edit can add or edit a
 * spell's details in a small modal; details whose spell no longer exists
 * are listed apart, to edit or delete, never lost.
 */
const props = defineProps({
    playerId: { type: Number, required: true },
    injuries: { type: Object, required: true }, // { spells: [{ start, end, sessions, open, note }], unmatched: [note] }
});
const emit = defineEmits(['saved']);
const { t, locale } = useI18n();
const { can } = useCan();
const canEdit = computed(() => can('attendance', 'edit'));

// The spell dates with the year: a spell can reach back into a previous season.
const day = (key) => parseDay(key).toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: 'numeric' });

const editing = ref(null); // { id, start_date, body_part, description, returned_on }
const errors = ref({});
const saving = ref(false);

function open(startDate, note) {
    errors.value = {};
    editing.value = {
        id: note?.id ?? null,
        start_date: startDate,
        body_part: note?.body_part ?? '',
        description: note?.description ?? '',
        returned_on: note?.returned_on ?? '',
    };
}
const close = () => { editing.value = null; };
// Only att.* values are translation keys; Laravel's own messages show as they are.
const tr = (e) => (typeof e === 'string' && e.startsWith('att.') ? t(e) : e);
const errorOf = (key) => tr(errors.value[key]?.[0]);

async function save() {
    const e = editing.value;
    const details = { body_part: e.body_part || null, description: e.description || null, returned_on: e.returned_on || null };
    saving.value = true;
    errors.value = {};
    try {
        if (e.id) await window.axios.put(route('attendance.injury-notes.update', e.id), details);
        else await window.axios.post(route('attendance.injury-notes.store', props.playerId), { start_date: e.start_date, ...details });
        close();
        emit('saved');
    } catch (error) {
        errors.value = error.response?.status === 422 ? error.response.data.errors : { form: [t('att.injury.save_error')] };
    } finally {
        saving.value = false;
    }
}

async function remove(note) {
    if (!window.confirm(t('att.confirm_delete'))) return;
    await window.axios.delete(route('attendance.injury-notes.destroy', note.id));
    emit('saved');
}

const input = 'mt-1 block w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
const smallButton = 'inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-200 hover:bg-primary-50 dark:text-primary-300 dark:ring-primary-800 dark:hover:bg-primary-500/10';
</script>

<template>
    <div>
        <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('att.injury.title') }}</h4>
        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ t('att.injury.help') }}</p>

        <p v-if="!injuries.spells.length" class="py-3 text-sm text-slate-500">{{ t('att.injury.none') }}</p>
        <ol v-else class="mt-2 space-y-2">
            <li v-for="spell in injuries.spells" :key="spell.start" class="rounded-xl p-3 ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="font-medium">{{ day(spell.start) }}</span>
                    <span class="text-slate-400 rtl:rotate-180">→</span>
                    <span>{{ spell.open ? '…' : day(spell.end) }}</span>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="spell.open ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'"
                    >{{ spell.open ? t('att.injury.open') : t('att.injury.closed') }}</span>
                    <span class="text-xs text-slate-500">{{ t('att.injury.col.sessions') }}: {{ spell.sessions }}</span>
                    <button v-if="canEdit" type="button" :class="[smallButton, 'ms-auto']" @click="open(spell.start, spell.note)">
                        <Icon name="pencil" />{{ spell.note ? t('att.injury.edit_details') : t('att.injury.add_details') }}
                    </button>
                </div>
                <div v-if="spell.note" class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                    <p><b v-if="spell.note.body_part">{{ spell.note.body_part }}</b></p>
                    <p v-if="spell.note.description" class="whitespace-pre-line">{{ spell.note.description }}</p>
                    <p v-if="spell.note.returned_on" class="text-xs text-slate-500">{{ t('att.injury.col.returned_on') }}: {{ day(spell.note.returned_on) }}</p>
                </div>
            </li>
        </ol>

        <div v-if="injuries.unmatched.length" class="mt-3 rounded-xl bg-amber-50 p-3 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-900">
            <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">{{ t('att.injury.unmatched') }}</p>
            <p class="text-xs text-amber-800 dark:text-amber-300">{{ t('att.injury.unmatched_help') }}</p>
            <ul class="mt-2 space-y-1">
                <li v-for="note in injuries.unmatched" :key="note.id" class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="font-medium">{{ day(note.start_date) }}</span>
                    <span v-if="note.body_part">— {{ note.body_part }}</span>
                    <span v-if="canEdit" class="ms-auto inline-flex gap-1">
                        <button type="button" :class="smallButton" @click="open(note.start_date, note)"><Icon name="pencil" />{{ t('att.edit') }}</button>
                        <button type="button" class="inline-flex items-center rounded-md p-1 text-slate-400 hover:text-rose-600" :title="t('att.delete')" @click="remove(note)"><Icon name="trash" /></button>
                    </span>
                </li>
            </ul>
        </div>

        <Modal :show="editing !== null" max-width="md" @close="close">
            <form v-if="editing" class="space-y-3 p-5" @submit.prevent="save">
                <h3 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.injury.modal_title', { date: day(editing.start_date) }) }}</h3>
                <label class="block text-sm">{{ t('att.injury.col.body_part') }}
                    <input v-model="editing.body_part" type="text" maxlength="60" :class="input" />
                </label>
                <InputError :message="errorOf('body_part')" />
                <label class="block text-sm">{{ t('att.injury.col.description') }}
                    <textarea v-model="editing.description" rows="3" maxlength="2000" :class="input"></textarea>
                </label>
                <InputError :message="errorOf('description')" />
                <label class="block text-sm">{{ t('att.injury.col.returned_on') }}
                    <input v-model="editing.returned_on" type="date" :min="editing.start_date" :class="input" />
                </label>
                <InputError :message="errorOf('returned_on')" />
                <InputError :message="errorOf('start_date')" />
                <InputError :message="errorOf('form')" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="close">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="saving" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('att.save') }}</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
