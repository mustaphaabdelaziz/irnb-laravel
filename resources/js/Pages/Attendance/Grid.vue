<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import { useCan } from '@/Composables/useCan';

const props = defineProps({
    category: { type: Object, required: true },
    month: { type: String, required: true },
    sessions: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    cells: { type: Object, default: () => ({}) }, // { playerId: { sessionId: code } }
});
const { t, locale } = useI18n();
const { can } = useCan();
const page = usePage();
const editable = computed(() => can('attendance', 'edit'));
const lang = computed(() => (locale.value === 'ar' ? 'ar' : locale.value));

const values = reactive(JSON.parse(JSON.stringify(props.cells)));
const dirty = reactive(new Set());
const sent = ref([]); // session ids in the order last posted, to map `columns.{i}` errors back

const inRoster = (pid, sid) => values[pid] !== undefined && sid in values[pid];
const dayLabel = (s) => new Date(`${s.date}T00:00:00`).toLocaleDateString(lang.value, { weekday: 'short', day: 'numeric' });
const monthLabel = computed(() => new Date(`${props.month}-01T00:00:00`).toLocaleDateString(lang.value, { month: 'long', year: 'numeric' }));

function onInput(pid, sid, event) {
    values[pid][sid] = event.target.value.toUpperCase();
    dirty.add(sid);
}
function cellError(pid, sid) {
    const i = sent.value.indexOf(sid);
    const e = i === -1 ? null : page.props.errors?.[`columns.${i}.codes.${pid}`];
    return e ? t(e) : null;
}
function columnError(sid) {
    const i = sent.value.indexOf(sid);
    return i === -1 ? null : page.props.errors?.[`columns.${i}`];
}
const columnMessages = computed(() => {
    const errors = page.props.errors ?? {};

    return Object.keys(errors)
        .filter((key) => /^columns\.\d+$/.test(key) || key === 'session' || key === 'marks')
        .map((key) => (typeof errors[key] === 'string' && errors[key].startsWith('att.') ? t(errors[key]) : errors[key]));
});
const codeStyle = (code) => ({
    'bg-amber-50 dark:bg-amber-500/10': /^R/.test(code),
    'bg-orange-50 dark:bg-orange-500/10': /^D/.test(code),
    'bg-sky-50 dark:bg-sky-500/10': code === 'B',
    'bg-slate-100 dark:bg-slate-800': code === 'AE',
    'bg-rose-50 dark:bg-rose-500/10': code === 'AN',
});

// Spreadsheet-style navigation: arrows / Enter move between cells (mirrored in RTL).
function onKey(event, r, c) {
    const rtl = document.documentElement.dir === 'rtl';
    const moves = { ArrowUp: [-1, 0], ArrowDown: [1, 0], Enter: [1, 0], ArrowLeft: [0, rtl ? 1 : -1], ArrowRight: [0, rtl ? -1 : 1] };
    const move = moves[event.key];
    if (!move) return;
    event.preventDefault();
    let [nr, nc] = [r + move[0], c + move[1]];
    while (nr >= 0 && nr < props.rows.length && nc >= 0 && nc < props.sessions.length) {
        const el = document.querySelector(`[data-cell="${nr}-${nc}"]`);
        if (el) return el.focus();
        [nr, nc] = [nr + move[0], nc + move[1]];
    }
}

function save() {
    const columns = props.sessions
        .filter((s) => dirty.has(s.id))
        .map((s) => ({ session_id: s.id, codes: Object.fromEntries(props.rows.filter((r) => inRoster(r.id, s.id)).map((r) => [r.id, values[r.id][s.id] ?? ''])) }))
        .filter((c) => Object.keys(c.codes).length);
    if (!columns.length) return;
    sent.value = columns.map((c) => c.session_id);
    router.post(route('attendance.grid.save'), { columns }, { preserveScroll: true, preserveState: 'errors', onSuccess: () => dirty.clear() });
}
</script>

<template>
    <Head :title="t('att.grid')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <Link :href="route('attendance.index', { category_id: category.id, month })" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 rtl:rotate-180"><Icon name="back" /></Link>
                    <h1 class="text-lg font-bold capitalize text-slate-900 dark:text-slate-100">{{ t('att.grid') }} · {{ category.name }} · {{ monthLabel }}</h1>
                </div>
                <button v-if="editable" :disabled="!dirty.size" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50" @click="save">{{ t('att.save') }}</button>
            </div>
        </template>

        <p class="mb-3 text-xs text-slate-500">{{ t('att.grid_help') }}</p>
        <InputError :message="page.props.errors?.columns ? t(page.props.errors.columns) : null" />
        <InputError v-for="(msg, idx) in columnMessages" :key="idx" :message="msg" />

        <p v-if="!sessions.length" class="text-sm text-slate-500">{{ t('att.no_sessions') }}</p>
        <div v-else class="overflow-x-auto rounded-xl bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <table class="text-sm">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/50">
                        <th class="sticky start-0 z-10 bg-slate-50 p-2 text-start dark:bg-slate-800">{{ t('att.player') }}</th>
                        <th v-for="s in sessions" :key="s.id" class="min-w-[3.5rem] p-1 text-center text-xs font-semibold" :class="columnError(s.id) ? 'text-rose-600' : dirty.has(s.id) ? 'text-primary-600' : 'text-slate-500'" :title="columnError(s.id) ? t(columnError(s.id)) : ''">
                            <Link :href="route('attendance.sessions.show', s.id)" class="hover:underline">{{ dayLabel(s) }}</Link>
                            <div class="font-normal" :class="s.state === 'held' ? 'text-emerald-600' : 'text-slate-400'">{{ s.state === 'held' ? '✓' : '·' }}</div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, r) in rows" :key="row.id" class="border-t border-slate-100 dark:border-slate-800">
                        <td class="sticky start-0 z-10 whitespace-nowrap bg-white p-2 font-medium dark:bg-slate-900">{{ row.name }}</td>
                        <td v-for="(s, c) in sessions" :key="s.id" class="p-0.5 text-center">
                            <template v-if="inRoster(row.id, s.id)">
                                <input
                                    :data-cell="`${r}-${c}`"
                                    :value="values[row.id][s.id]"
                                    :disabled="!editable"
                                    maxlength="6"
                                    dir="ltr"
                                    class="w-14 rounded border-slate-200 p-1 text-center font-mono text-xs uppercase dark:border-slate-700 dark:bg-slate-900"
                                    :class="[codeStyle(values[row.id][s.id]), cellError(row.id, s.id) ? 'border-rose-500 ring-1 ring-rose-500' : '']"
                                    :title="cellError(row.id, s.id) ?? ''"
                                    @input="onInput(row.id, s.id, $event)"
                                    @keydown="onKey($event, r, c)"
                                />
                            </template>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
