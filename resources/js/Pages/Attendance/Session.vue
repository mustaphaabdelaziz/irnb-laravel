<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { useCan } from '@/Composables/useCan';
import { useAttendanceCodes } from '@/Composables/useAttendanceCodes';

const props = defineProps({
    session: { type: Object, required: true },
    saved: { type: Boolean, default: false },
    rows: { type: Array, default: () => [] },
    candidates: { type: Array, default: () => [] },
    lastCoach: { type: String, default: null },
    statuses: { type: Array, default: () => [] },
    reasons: { type: Array, default: () => [] },
    allCategories: { type: Array, default: () => [] },
});
const { t, locale } = useI18n();
const { can } = useCan();
const { label, chipStyle, takesMinutes } = useAttendanceCodes();
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
    if (!rows.value.some((r) => r.player_id === p.id)) rows.value.push({ player_id: p.id, name: p.name, category: null, status: 'present', minutes: null, reason: null, note: '' });
    query.value = '';
}
const removeRow = (row) => (rows.value = rows.value.filter((r) => r !== row));

const saving = ref(false);
function save() {
    router.put(route('attendance.sessions.marks', props.session.id), {
        ...log,
        marks: rows.value.map(({ name, category, ...mark }) => mark),
    }, { preserveScroll: true, preserveState: 'errors', onStart: () => (saving.value = true), onFinish: () => (saving.value = false) });
}
const rowError = (i) => ['minutes', 'reason', 'note', 'status'].map((f) => errors.value[`marks.${i}.${f}`]).find(Boolean);

// ---- Cancel / move ----
const showCancel = ref(false);
const cancelForm = useForm({ reason: '' });
const submitCancel = () => cancelForm.post(route('attendance.sessions.cancel', props.session.id), { onSuccess: () => (showCancel.value = false) });
const showMove = ref(false);
const moveForm = useForm({ date: props.session.date, start_time: props.session.start_time, end_time: props.session.end_time });
const submitMove = () => moveForm.post(route('attendance.sessions.move', props.session.id), { onSuccess: () => (showMove.value = false) });

// ---- Categories of a pre-season session: editable until its marks are first saved ----
const canEditCategories = computed(() => editable.value && props.session.kind === 'preseason' && !props.saved);
const showCategories = ref(false);
const categoriesForm = useForm({ category_ids: [] });
function openCategories() {
    categoriesForm.category_ids = props.session.categories.map((c) => c.id);
    categoriesForm.clearErrors();
    showCategories.value = true;
}
// 'errors' keeps the modal open on a validation error; a success remounts the page with the new roster.
const submitCategories = () => categoriesForm.put(route('attendance.sessions.categories', props.session.id), {
    preserveScroll: true,
    preserveState: 'errors',
    onSuccess: () => (showCategories.value = false),
});

const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
const tr = (e) => (typeof e === 'string' && e.startsWith('att.') ? t(e) : e);
</script>

<template>
    <Head :title="t('attendance')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <Link :href="route('attendance.index', { category_id: session.category_id, month: session.date.slice(0, 7) })" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 rtl:rotate-180"><Icon name="back" /></Link>
                    <div>
                        <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ session.category }} · {{ t(`att.kind.${session.kind}`) }}</h1>
                        <p class="text-sm capitalize text-slate-500">{{ dateLabel }} · <span dir="ltr">{{ session.start_time }}–{{ session.end_time }}</span> · {{ t(`att.state.${session.state}`) }}</p>
                        <p v-if="session.moved_from" class="text-xs text-slate-400">{{ t('att.moved_from', { date: session.moved_from }) }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 print:hidden">
                    <a v-if="!cancelled" :href="sheetHref" target="_blank" :title="t('att.sheet.print_hint')" class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800"><Icon name="print" />{{ t('att.sheet.print_session') }}</a>
                    <a v-if="!cancelled && saved" :href="filledSheetHref" target="_blank" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800">{{ t('att.sheet.print_filled') }}</a>
                    <template v-if="editable">
                        <button v-if="canEditCategories" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-amber-700 ring-1 ring-amber-200 hover:bg-amber-50 dark:text-amber-300 dark:ring-amber-900" @click="openCategories">{{ t('att.edit_categories') }}</button>
                        <button class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700" @click="showMove = true">{{ t('att.move') }}</button>
                        <button class="rounded-lg px-3 py-1.5 text-sm font-semibold text-rose-600 ring-1 ring-rose-200 hover:bg-rose-50 dark:ring-rose-900" @click="showCancel = true">{{ t('att.cancel') }}</button>
                    </template>
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
                    <button v-if="editable" class="rounded-lg px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200 hover:bg-emerald-50 dark:text-emerald-300 dark:ring-emerald-900" @click="allPresent">{{ t('att.all_present') }}</button>
                </div>
                <ul>
                    <li v-for="(row, i) in rows" :key="row.player_id" class="border-b border-slate-100 p-3 last:border-0 dark:border-slate-800">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="min-w-[10rem] flex-1 font-medium text-slate-900 dark:text-slate-100">
                                {{ row.name }}
                                <span v-if="row.category" class="ms-1 text-xs font-normal text-slate-400">{{ row.category }}</span>
                            </span>
                            <button v-for="s in statuses" :key="s" type="button" :disabled="!editable" class="rounded-lg px-2 py-1 text-xs font-semibold" :class="row.status === s ? '' : idleChip" :style="row.status === s ? chipStyle(s) : null" @click="setStatus(row, s)">{{ label(s) }}</button>
                            <button v-if="editable" type="button" class="p-1 text-slate-300 hover:text-rose-600" :title="t('att.remove')" @click="removeRow(row)"><Icon name="trash" /></button>
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
                                <span class="text-xs text-slate-500">{{ [c.category, c.status].filter(Boolean).join(' · ') }}</span>
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

        <Modal :show="showMove" max-width="md" @close="showMove = false">
            <form class="space-y-3 p-5" @submit.prevent="submitMove">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.move') }}</h2>
                <label class="block text-sm">{{ t('att.date') }}<input v-model="moveForm.date" type="date" :class="[input, 'mt-1 block w-full']" /></label>
                <div class="flex gap-2">
                    <label class="flex-1 text-sm">{{ t('att.start') }}<input v-model="moveForm.start_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                    <label class="flex-1 text-sm">{{ t('att.end') }}<input v-model="moveForm.end_time" type="time" :class="[input, 'mt-1 block w-full']" /></label>
                </div>
                <InputError v-for="(e, k) in moveForm.errors" :key="k" :message="tr(e)" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="showMove = false">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="moveForm.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
                </div>
            </form>
        </Modal>

        <Modal :show="showCategories" max-width="md" @close="showCategories = false">
            <form class="space-y-3 p-5" @submit.prevent="submitCategories">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.categories') }}</h2>
                <p class="text-xs text-slate-500">{{ t('att.categories_help') }}</p>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    <label v-for="c in allCategories" :key="c.id" class="inline-flex items-center gap-1.5">
                        <input v-model="categoriesForm.category_ids" type="checkbox" :value="c.id" class="rounded border-slate-300 text-primary-600 dark:border-slate-700 dark:bg-slate-900" />
                        {{ c.name }}
                    </label>
                </div>
                <InputError v-for="(e, k) in categoriesForm.errors" :key="k" :message="tr(e)" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:ring-slate-700" @click="showCategories = false">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="categoriesForm.processing || !categoriesForm.category_ids.length" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('att.save') }}</button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
