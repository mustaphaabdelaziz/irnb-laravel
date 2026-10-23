<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import IconButton from '@/Components/IconButton.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { useCan } from '@/Composables/useCan';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';

const props = defineProps({
    session: { type: Object, required: true },
    saved: { type: Boolean, default: false },
    marksCount: { type: Number, default: 0 },
    rows: { type: Array, default: () => [] },
    candidates: { type: Array, default: () => [] },
    lastCoach: { type: String, default: null },
    statuses: { type: Array, default: () => [] },
    reasons: { type: Array, default: () => [] },
    allCategories: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    playerStatuses: { type: Array, default: () => [] },
});
const { t, locale } = useI18n();
const { can } = useCan();
const { codes, label, chipStyle, takesMinutes } = useAttendanceCodes();
// A hidden custom code is offered only on a row whose saved mark already has it.
const savedStatus = Object.fromEntries(props.rows.map((r) => [r.player_id, r.status]));
const offered = (row, s) => codes.value[s]?.active !== false || savedStatus[row.player_id] === s;
const page = usePage();
const errors = computed(() => page.props.errors ?? {});

const cancelled = computed(() => props.session.state === 'cancelled');
const editable = computed(() => can('attendance', 'edit') && !cancelled.value);

// Paper sheet for this session: blank, or with the marks once they are saved.
const sheetHref = computed(() => route('attendance.sheets.session', props.session.id));
const filledSheetHref = computed(() => route('attendance.sheets.session', { session: props.session.id, filled: 1 }));
const dateLabel = computed(() => new Date(`${props.session.date}T00:00:00`).toLocaleDateString(locale.value === 'ar' ? 'ar' : locale.value, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));

const rows = ref(props.rows.map((r) => ({ ...r, note: r.note ?? '' })));
const log = reactive({ coach: props.session.coach ?? props.lastCoach ?? '', title: props.session.title ?? '', notes: props.session.notes ?? '' });

const takesReason = (s) => s === 'absent_excused' || s === 'not_training';
function setStatus(row, status) {
    row.status = status;
    if (!takesMinutes(status)) row.minutes = null;
    if (!takesReason(status)) row.reason = null;
    if (status === 'absent_excused' && !row.reason) row.reason = 'other';
}
const allPresent = () => rows.value.forEach((r) => setStatus(r, 'present'));

const idleChip = 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400';
const counts = computed(() => rows.value.reduce((acc, r) => ((acc[r.status] = (acc[r.status] ?? 0) + 1), acc), {}));

// ---- Add a player by hand: any active player, searched by full name ----
const query = ref('');
// Lower-cased without accents or Arabic vowel marks, so "eric" finds "Éric".
const fold = (v) => String(v ?? '').normalize('NFKD').replace(/\p{M}/gu, '').toLocaleLowerCase();
const matches = computed(() => {
    const q = fold(query.value.trim());
    if (!q) return [];
    const listed = new Set(rows.value.map((r) => r.player_id));

    return props.candidates.filter((c) => !listed.has(c.id) && fold(c.name).includes(q)).slice(0, 20);
});
function addPlayer(p) {
    if (!rows.value.some((r) => r.player_id === p.id)) rows.value.push({ player_id: p.id, name: p.name, picture: p.picture, category: null, status: 'present', minutes: null, reason: null, note: '' });
    query.value = '';
}
const removeRow = (row) => (rows.value = rows.value.filter((r) => r !== row));

const saving = ref(false);
function save() {
    router.put(route('attendance.sessions.marks', props.session.id), {
        ...log,
        marks: rows.value.map(({ name, picture, category, ...mark }) => mark),
    }, { preserveScroll: true, preserveState: 'errors', onStart: () => (saving.value = true), onFinish: () => (saving.value = false) });
}
const rowError = (i) => ['minutes', 'reason', 'note', 'status'].map((f) => errors.value[`marks.${i}.${f}`]).map(tr).find(Boolean);

// ---- Cancel ----
const showCancel = ref(false);
const cancelForm = useForm({ reason: '' });
const submitCancel = () => cancelForm.post(route('attendance.sessions.cancel', props.session.id), { onSuccess: () => (showCancel.value = false) });

// ---- Delete / erase marks ----
// Erasing only makes sense once there is something to erase (marks, or a cancellation).
const canReset = computed(() => props.saved || cancelled.value);
const showDelete = ref(false);
const deleteMode = ref('delete');
const deleting = ref(false);
const openDelete = () => {
    deleteMode.value = 'delete';
    showDelete.value = true;
};
const submitDelete = () => {
    const options = { onStart: () => (deleting.value = true), onFinish: () => (deleting.value = false), onSuccess: () => (showDelete.value = false) };
    if (deleteMode.value === 'reset') {
        router.post(route('attendance.sessions.reset', props.session.id), {}, options);
    } else {
        router.delete(route('attendance.sessions.destroy', props.session.id), options);
    }
};
// ---- Edit: details any time; branch, roster, categories and kind until marks are first saved ----
const handAdded = computed(() => props.session.kind !== 'regular');
const showEdit = ref(false);
const editForm = useForm({ title: '', date: '', start_time: '', end_time: '', coach: '', notes: '', branch_id: null, roster_status_ids: [], kind: 'extra', category_ids: [] });
const sortedKey = (ids) => [...ids].sort((a, b) => a - b).join(',');
let prefilledStatusKey = '';
function openEdit() {
    const s = props.session;
    prefilledStatusKey = sortedKey(s.roster_status_ids ?? []);
    Object.assign(editForm, {
        title: s.title ?? '', date: s.date, start_time: s.start_time, end_time: s.end_time, coach: s.coach ?? '', notes: s.notes ?? '',
        branch_id: s.branch_id, roster_status_ids: [...(s.roster_status_ids ?? [])], kind: s.kind, category_ids: s.categories.map((c) => c.id),
    });
    editForm.clearErrors();
    showEdit.value = true;
}
// Locked fields are left out, so the server keeps them as they are.
const submitEdit = () => editForm
    .transform((d) => {
        const out = { title: d.title || null, date: d.date, start_time: d.start_time, end_time: d.end_time, coach: d.coach || null, notes: d.notes || null };
        if (!props.saved) {
            // Only a real change is sent: the pre-filled set is the effective default, and sending it back would pin it.
            if (props.playerStatuses.length && sortedKey(d.roster_status_ids) !== prefilledStatusKey) out.roster_status_ids = d.roster_status_ids;
            if (handAdded.value) {
                out.kind = d.kind;
                if (props.branches.length) out.branch_id = d.branch_id;
            }
            if (d.kind === 'preseason') out.category_ids = d.category_ids;
        }
        return out;
    })
    // 'errors' keeps the modal open on a validation error; a success remounts the page (new roster, side panel values).
    .put(route('attendance.sessions.update', props.session.id), { preserveScroll: true, preserveState: 'errors', onSuccess: () => (showEdit.value = false) });

const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
const tr = (e) => (typeof e === 'string' && e.startsWith('att.') ? t(e) : e);
</script>

<template>
    <Head :title="t('attendance')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <Link :href="route('attendance.index', { category_id: session.category_id, month: session.date.slice(0, 7), branch_id: session.branch_id ?? undefined })" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 rtl:rotate-180"><Icon name="back" /></Link>
                    <div>
                        <h1 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-slate-100">
                            <img v-if="session.branch?.logo_url" :src="session.branch.logo_url" alt="" class="h-6 w-6 rounded object-contain" />
                            <span>{{ [session.branch?.name, session.category].filter(Boolean).join(' · ') }} · {{ t(`att.kind.${session.kind}`) }}</span>
                        </h1>
                        <p class="text-sm capitalize text-slate-500">{{ dateLabel }} · <span dir="ltr">{{ session.start_time }}–{{ session.end_time }}</span> · {{ t(`att.state.${session.state}`) }}</p>
                        <p v-if="session.moved_from" class="text-xs text-slate-400">{{ t('att.moved_from', { date: session.moved_from }) }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 print:hidden">
                    <IconButton v-if="!cancelled" :href="sheetHref" external target="_blank" :title="t('att.sheet.print_hint')" icon="print" :label="t('att.sheet.print_session')" />
                    <IconButton v-if="!cancelled && saved" :href="filledSheetHref" external target="_blank" icon="clipboard" :label="t('att.sheet.print_filled')" />
                    <template v-if="editable">
                        <IconButton icon="pencil" :label="t('att.edit')" @click="openEdit" />
                        <IconButton icon="xcircle" :label="t('att.cancel')" variant="danger" @click="showCancel = true" />
                    </template>
                    <IconButton v-if="can('attendance', 'edit')" icon="trash" :label="t('att.delete_session')" variant="danger" @click="openDelete" />
                </div>
            </div>
        </template>

        <p v-if="cancelled" class="mb-4 rounded-lg bg-slate-100 p-3 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('att.cancelled_because', { reason: session.cancel_reason }) }}</p>
        <p v-else-if="!saved" class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ t('att.not_saved') }}</p>
        <InputError :message="tr(errors.session)" class="mb-2" />

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800 lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 p-3 dark:border-slate-800">
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span v-for="s in statuses" :key="s" v-show="counts[s]" class="rounded-full px-2 py-0.5" :style="chipStyle(s)">{{ label(s) }}: {{ counts[s] }}</span>
                    </div>
                    <IconButton v-if="editable" icon="check" :label="t('att.all_present')" variant="success" size="sm" @click="allPresent" />
                </div>
                <ul>
                    <li v-for="(row, i) in rows" :key="row.player_id" class="border-b border-slate-100 p-3 last:border-0 dark:border-slate-800">
                        <div class="flex flex-wrap items-center gap-2">
                            <img v-if="row.picture" :src="row.picture" :alt="row.name" loading="lazy" class="h-9 w-9 shrink-0 rounded-full object-cover ring-1 ring-slate-200 dark:ring-slate-700" />
                            <span v-else class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-bold text-primary-600 ring-1 ring-primary-100 dark:bg-primary-500/10 dark:text-primary-300 dark:ring-primary-500/20">{{ (row.name || '?').charAt(0).toUpperCase() }}</span>
                            <span class="min-w-[10rem] flex-1 font-medium text-slate-900 dark:text-slate-100">
                                {{ row.name }}
                                <span v-if="row.category" class="ms-1 text-xs font-normal text-slate-400">{{ row.category }}</span>
                            </span>
                            <button v-for="s in statuses.filter((st) => offered(row, st))" :key="s" type="button" :disabled="!editable" class="rounded-lg px-2 py-1 text-xs font-semibold" :class="row.status === s ? '' : idleChip" :style="row.status === s ? chipStyle(s) : null" @click="setStatus(row, s)">{{ label(s) }}</button>
                            <IconButton v-if="editable" icon="trash" :label="t('att.remove')" variant="danger" plain size="sm" @click="removeRow(row)" />
                        </div>
                        <div v-if="takesMinutes(row.status) || takesReason(row.status) || row.note" class="mt-2 flex flex-wrap items-center gap-2">
                            <label v-if="takesMinutes(row.status)" class="text-xs text-slate-500">{{ t('att.minutes') }}
                                <input v-model.number="row.minutes" type="number" min="1" max="600" :disabled="!editable" :class="[input, 'ms-1 w-20']" />
                            </label>
                            <label v-if="takesReason(row.status)" class="text-xs text-slate-500">{{ t('att.reason') }}
                                <select v-model="row.reason" :disabled="!editable" :class="[input, 'ms-1']">
                                    <option :value="null">—</option>
                                    <option v-for="r in reasons" :key="r" :value="r">{{ t(`att.reason.${r}`) }}</option>
                                </select>
                            </label>
                            <input v-model="row.note" type="text" maxlength="255" :placeholder="t('att.note')" :disabled="!editable" :class="[input, 'min-w-[12rem] flex-1']" />
                        </div>
                        <InputError :message="rowError(i)" />
                    </li>
                </ul>
                <div v-if="editable && candidates.length" class="border-t border-slate-100 p-3 dark:border-slate-800">
                    <input v-model="query" type="search" :placeholder="t('att.add_player_search')" :aria-label="t('att.add_player')" :class="[input, 'w-full']" />
                    <ul v-if="matches.length" class="mt-2 max-h-64 divide-y divide-slate-100 overflow-y-auto rounded-lg ring-1 ring-slate-200 dark:divide-slate-800 dark:ring-slate-700">
                        <li v-for="c in matches" :key="c.id">
                            <button type="button" class="flex w-full flex-wrap items-center justify-between gap-x-3 px-3 py-1.5 text-start text-sm hover:bg-slate-50 dark:hover:bg-slate-800" @click="addPlayer(c)">
                                <span class="font-medium text-slate-900 dark:text-slate-100">{{ c.name }}</span>
                                <span class="text-xs text-slate-500">{{ [c.in_branch ? null : c.branches, c.category, c.status].filter(Boolean).join(' · ') }}</span>
                            </button>
                        </li>
                    </ul>
                    <p v-else-if="query.trim()" class="mt-2 text-xs text-slate-500">{{ t('no_results') }}</p>
                </div>
            </section>

            <section class="h-fit space-y-3 rounded-xl bg-white p-4 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <label class="block text-sm">{{ t('att.coach') }}<input v-model="log.coach" type="text" maxlength="100" :disabled="!editable" :class="[input, 'mt-1 block w-full']" /></label>
                <label class="block text-sm">{{ t('att.title_goal') }}<input v-model="log.title" type="text" maxlength="150" :placeholder="t('att.title_placeholder')" :disabled="!editable" :class="[input, 'mt-1 block w-full']" /></label>
                <label class="block text-sm">{{ t('att.notes') }}<textarea v-model="log.notes" rows="4" maxlength="2000" :disabled="!editable" :class="[input, 'mt-1 block w-full']"></textarea></label>
                <InputError :message="tr(errors.marks)" />
                <button v-if="editable" type="button" :disabled="saving || !rows.length" class="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50" @click="save">{{ t('att.save') }}</button>
            </section>
        </div>

        <Modal :show="showCancel" max-width="md" @close="showCancel = false">
            <form class="space-y-3 p-5" @submit.prevent="submitCancel">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.cancel') }}</h2>
                <label class="block text-sm">{{ t('att.cancel_reason') }}<input v-model="cancelForm.reason" type="text" maxlength="255" :class="[input, 'mt-1 block w-full']" /></label>
                <InputError :message="tr(cancelForm.errors.reason)" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="showCancel = false">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="cancelForm.processing" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">{{ t('att.cancel') }}</button>
                </div>
            </form>
        </Modal>

        <Modal :show="showDelete" max-width="md" @close="showDelete = false">
            <form class="space-y-3 p-5" @submit.prevent="submitDelete">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.delete_session') }}</h2>
                <label class="flex gap-2 rounded-lg p-3 text-sm ring-1 ring-slate-200 dark:ring-slate-700">
                    <input v-model="deleteMode" type="radio" value="delete" class="mt-0.5" />
                    <span>
                        <span class="block font-semibold text-slate-900 dark:text-slate-100">{{ t('att.delete_choice_delete') }}</span>
                        <span class="block text-slate-500">{{ t('att.delete_choice_delete_hint') }}</span>
                        <span v-if="session.kind === 'regular'" class="block text-slate-500">{{ t('att.delete_regular_hint') }}</span>
                    </span>
                </label>
                <label v-if="canReset" class="flex gap-2 rounded-lg p-3 text-sm ring-1 ring-slate-200 dark:ring-slate-700">
                    <input v-model="deleteMode" type="radio" value="reset" class="mt-0.5" />
                    <span>
                        <span class="block font-semibold text-slate-900 dark:text-slate-100">{{ t('att.delete_choice_reset') }}</span>
                        <span class="block text-slate-500">{{ t('att.delete_choice_reset_hint') }}</span>
                    </span>
                </label>
                <p v-if="marksCount" class="rounded-lg bg-rose-50 p-3 text-sm font-semibold text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ t('att.delete_marks_count', { count: marksCount }) }}</p>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="showDelete = false">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="deleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-50">{{ deleteMode === 'reset' ? t('att.reset_confirm') : t('att.delete_confirm') }}</button>
                </div>
            </form>
        </Modal>

        <Modal :show="showEdit" max-width="lg" @close="showEdit = false">
            <form class="space-y-3 p-5" @submit.prevent="submitEdit">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.edit_session') }}</h2>
                <label class="block text-sm">{{ t('att.title_goal') }}<input v-model="editForm.title" type="text" maxlength="150" :placeholder="t('att.title_placeholder')" :class="[input, 'mt-1 block w-full']" /></label>
                <label class="block text-sm">{{ t('att.date') }}<input v-model="editForm.date" type="date" :class="[input, 'mt-1 block w-full']" /></label>
                <div class="flex gap-2">
                    <label class="flex-1 text-sm">{{ t('att.start') }}<input v-model="editForm.start_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                    <label class="flex-1 text-sm">{{ t('att.end') }}<input v-model="editForm.end_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                </div>
                <label class="block text-sm">{{ t('att.coach') }}<input v-model="editForm.coach" type="text" maxlength="100" :class="[input, 'mt-1 block w-full']" /></label>
                <label class="block text-sm">{{ t('att.notes') }}<textarea v-model="editForm.notes" rows="3" maxlength="2000" :class="[input, 'mt-1 block w-full']"></textarea></label>

                <div v-if="!saved && (handAdded || playerStatuses.length)" class="space-y-3 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <div v-if="handAdded" class="inline-flex rounded-lg p-0.5 ring-1 ring-slate-200 dark:ring-slate-700" role="radiogroup">
                        <button v-for="k in ['extra', 'preseason']" :key="k" type="button" role="radio" :aria-checked="editForm.kind === k" class="rounded-md px-3 py-1 text-sm font-semibold" :class="editForm.kind === k ? (k === 'preseason' ? 'bg-amber-500 text-white' : 'bg-primary-600 text-white') : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'" @click="editForm.kind = k">{{ t(`att.kind.${k}`) }}</button>
                    </div>
                    <label v-if="handAdded && branches.length" class="block text-sm">{{ t('branch') }}
                        <select v-model="editForm.branch_id" :class="[input, 'mt-1 block w-full']">
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </label>
                    <fieldset v-if="editForm.kind === 'preseason'" class="text-sm">
                        <legend class="mb-1">{{ t('att.categories') }}</legend>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            <label v-for="c in allCategories" :key="c.id" class="inline-flex items-center gap-1.5">
                                <input v-model="editForm.category_ids" type="checkbox" :value="c.id" class="rounded border-slate-300 text-primary-600 dark:border-slate-700 dark:bg-slate-900" />
                                {{ c.name }}
                            </label>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ t('att.categories_help') }}</p>
                    </fieldset>
                    <fieldset v-if="playerStatuses.length" class="text-sm">
                        <legend class="mb-1">{{ t('att.roster_statuses') }}</legend>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            <label v-for="s in playerStatuses" :key="s.id" class="inline-flex items-center gap-1.5">
                                <input v-model="editForm.roster_status_ids" type="checkbox" :value="s.id" class="rounded border-slate-300 text-primary-600 dark:border-slate-700 dark:bg-slate-900" />
                                {{ s.name }}
                            </label>
                        </div>
                    </fieldset>
                </div>
                <p v-else-if="saved" class="rounded-lg bg-slate-50 p-2 text-xs text-slate-500 dark:bg-slate-800">{{ t('att.edit_locked_hint') }}</p>

                <InputError v-for="(e, k) in editForm.errors" :key="k" :message="tr(e)" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="showEdit = false">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="editForm.processing || (editForm.kind === 'preseason' && !saved && !editForm.category_ids.length) || (!saved && playerStatuses.length > 0 && !editForm.roster_status_ids.length)" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('att.save') }}</button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
