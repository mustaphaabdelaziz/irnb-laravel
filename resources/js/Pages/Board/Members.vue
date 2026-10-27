<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import IconButton from '@/Components/IconButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import OrgNode from '@/Components/Board/OrgNode.vue';
import { formatPhone } from '@/lib/phone';
import { useCan } from '@/Composables/useCan';

const props = defineProps({
    members: { type: Array, default: () => [] },
    terms: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    committees: { type: Array, default: () => [] },
    players: { type: Array, default: () => [] },
    selectedTermId: { type: [Number, String], default: null },
});
const { t } = useI18n();
const { can } = useCan();

function roleLabel(r) {
    const found = props.roles.find((x) => x.name === r);
    return found?.label || t(r);
}
const currentTerm = computed(() => props.terms.find((tm) => String(tm.id) === String(props.selectedTermId)));

// ---- Term switching / management ----
function switchTerm(id) {
    router.get(route('board.members'), { term: id || undefined }, { preserveState: false, preserveScroll: true });
}
const showTermForm = ref(false);
const editingTermId = ref(null);
const termForm = useForm({ name: '', start_date: '', end_date: '', is_current: false });
function openTermCreate() {
    editingTermId.value = null; termForm.reset(); termForm.clearErrors(); showTermForm.value = true;
}
function openTermEdit(tm) {
    editingTermId.value = tm.id;
    termForm.clearErrors();
    termForm.name = tm.name;
    // Slice anyway: a stray timestamp would leave <input type="date"> blank
    // while the old value is silently re-sent on save.
    termForm.start_date = tm.start_date ? String(tm.start_date).slice(0, 10) : '';
    termForm.end_date = tm.end_date ? String(tm.end_date).slice(0, 10) : '';
    termForm.is_current = !!tm.is_current;
    showTermForm.value = true;
}
function submitTerm() {
    const opts = { preserveScroll: true, onSuccess: () => { showTermForm.value = false; } };
    if (editingTermId.value) termForm.put(route('board.terms.update', editingTermId.value), opts);
    else termForm.post(route('board.terms.store'), opts);
}

// ---- Organigram ----
// Rows come from each role's level: top (president), bureau (secretary-general,
// treasurer…), then the committees; members with a member-level role and no
// committee are listed under "Other members".
const levelOf = (role) => props.roles.find((r) => r.name === role)?.level || 'member';
const active = computed(() => props.members.filter((m) => m.status === 'active'));
const former = computed(() => props.members.filter((m) => m.status !== 'active'));

// One box per member holding the role, or one empty box when nobody does.
function roleRow(level) {
    return props.roles.filter((r) => r.level === level).flatMap((r) => {
        const holders = active.value.filter((m) => m.role === r.name);
        return holders.length ? holders.map((m) => ({ key: `m${m.id}`, role: r, member: m })) : [{ key: `r${r.id}`, role: r, member: null }];
    });
}
const topRow = computed(() => roleRow('top'));
const bureauRow = computed(() => roleRow('bureau'));

// Seats per committee: one head, two deputies (empty ones shown), any members.
const SEATS = { head: 1, deputy: 2 };
const committeeBoxes = computed(() => props.committees.map((c) => {
    const seated = active.value
        .map((m) => ({ member: m, position: m.committees?.find((x) => x.id === c.id)?.pivot?.position }))
        .filter((s) => s.position);
    const fill = (position, n) => {
        const list = seated.filter((s) => s.position === position).map((s) => s.member);
        return Array.from({ length: Math.max(n, list.length) }, (_, i) => list[i] || null);
    };
    return {
        committee: c,
        head: fill('head', SEATS.head),
        deputies: fill('deputy', SEATS.deputy),
        members: seated.filter((s) => s.position === 'member').map((s) => s.member),
    };
}));
const others = computed(() => active.value.filter((m) => levelOf(m.role) === 'member' && !m.committees?.length));

// ---- Player picker (empty seat → pick a player) ----
const picker = ref(null); // { title, onPick(player) }
const pickerSearch = ref('');
const pickerError = ref('');
const pickerBusy = ref(false);
const pickerPlayers = computed(() => {
    const q = pickerSearch.value.trim().toLowerCase();
    const list = q ? props.players.filter((p) => `${p.name} ${p.membership_id}`.toLowerCase().includes(q)) : props.players;
    return list.slice(0, 40);
});
const onBoard = computed(() => new Set(props.members.map((m) => m.player_id)));
function openPicker(title, onPick) {
    if (!props.selectedTermId) return;
    pickerSearch.value = ''; pickerError.value = '';
    picker.value = { title, onPick };
}
// Errors are i18n keys (seat limits) or already-translated validation messages.
const firstError = (errors) => t(Object.values(errors)[0] || 'error');
const pickOpts = {
    preserveScroll: true,
    onStart: () => { pickerBusy.value = true; },
    onFinish: () => { pickerBusy.value = false; },
    onSuccess: () => { picker.value = null; },
    onError: (errors) => { pickerError.value = firstError(errors); },
};
function fillRole(role) {
    openPicker(roleLabel(role.name), (p) => router.post(route('board.members.store'), {
        player_id: p.id, role: role.name, status: 'active', board_term_id: props.selectedTermId,
    }, pickOpts));
}
function fillSeat(committee, position) {
    openPicker(`${committee.name} — ${t(`board.position.${position}`)}`, (p) => router.post(route('board.committees.members.store', committee.id), {
        player_id: p.id, position, board_term_id: props.selectedTermId,
    }, pickOpts));
}

// ---- Seat menu (filled seat → change position / edit / remove) ----
const selected = ref(null); // { member, committee?, position? }
const seatError = ref('');
function openSeat(member, committee = null, position = null) {
    seatError.value = '';
    selected.value = { member, committee, position };
}
const seatOpts = {
    preserveScroll: true,
    onSuccess: () => { selected.value = null; },
    onError: (errors) => { seatError.value = firstError(errors); },
};
function changePosition(position) {
    const { member, committee } = selected.value;
    router.put(route('board.committees.members.update', [committee.id, member.id]), { position }, seatOpts);
}
function leaveCommittee() {
    const { member, committee } = selected.value;
    router.delete(route('board.committees.members.destroy', [committee.id, member.id]), seatOpts);
}
const committeeName = (id) => props.committees.find((c) => c.id === id)?.name || '';

// ---- Member form (add / edit role, term, status) ----
const showForm = ref(false);
const editingId = ref(null);
// Name, contact and photo come from the picked player; the member adds its board role.
const form = useForm({
    player_id: null, role: props.roles[0]?.name || 'member', status: 'active', board_term_id: props.selectedTermId,
});
const playerSearch = ref('');
const showPlayerList = ref(false);
const filteredPlayers = computed(() => {
    const q = playerSearch.value.trim().toLowerCase();
    const list = q ? props.players.filter((p) => `${p.name} ${p.membership_id}`.toLowerCase().includes(q)) : props.players;
    return list.slice(0, 30);
});
function pickPlayer(p) {
    form.player_id = p.id;
    playerSearch.value = p.name;
    showPlayerList.value = false;
}
function clearPlayer() {
    form.player_id = null; playerSearch.value = '';
}
function openCreate() {
    editingId.value = null;
    form.reset(); form.clearErrors();
    form.role = props.roles.find((r) => r.level === 'member')?.name || props.roles[0]?.name || 'member';
    form.board_term_id = props.selectedTermId;
    playerSearch.value = '';
    showForm.value = true;
}
function openEdit(m) {
    selected.value = null;
    editingId.value = m.id;
    form.reset(); form.clearErrors();
    form.player_id = m.player_id; form.role = m.role; form.status = m.status;
    form.board_term_id = m.board_term_id || props.selectedTermId;
    playerSearch.value = m.player_id ? m.name : '';
    showForm.value = true;
}
function submit() {
    const opts = { preserveScroll: true, onSuccess: () => { showForm.value = false; } };
    if (editingId.value) form.post(route('board.members.update', editingId.value), opts);
    else form.post(route('board.members.store'), opts);
}
const removeTarget = ref(null);
function remove() {
    const m = removeTarget.value;
    removeTarget.value = null;
    selected.value = null;
    router.delete(route('board.members.destroy', m.id), { preserveScroll: true });
}

const showFormer = ref(false);
function fmt(d) { return d ? String(d).slice(0, 10) : ''; }
</script>

<template>
    <Head :title="t('board_members')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('board.index')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"><Icon name="back" /></Link>
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ t('board_members') }}</h1>
            </div>
        </template>

        <div class="space-y-5">
            <!-- Term bar -->
            <div class="card flex flex-wrap items-center gap-3 p-4">
                <div class="flex items-center gap-2">
                    <Icon name="calendar" class="text-slate-400" />
                    <span class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ t('term') }}</span>
                </div>
                <select :value="selectedTermId" @change="switchTerm($event.target.value)" class="rounded-xl border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                    <option v-for="tm in terms" :key="tm.id" :value="tm.id">{{ tm.name }}{{ tm.is_current ? ' ★' : '' }}</option>
                </select>
                <span v-if="currentTerm && (currentTerm.start_date || currentTerm.end_date)" class="text-sm text-slate-500 dark:text-slate-400">
                    {{ fmt(currentTerm.start_date) || '…' }} → {{ fmt(currentTerm.end_date) || '…' }}
                </span>
                <div class="ms-auto flex gap-2">
                    <IconButton v-if="currentTerm" icon="pencil" :label="t('edit_term')" @click="openTermEdit(currentTerm)" />
                    <IconButton icon="plus" :label="t('new_term')" @click="openTermCreate" />
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ selectedTermId ? t('board.org_hint') : t('board.org_needs_term') }}</p>
                <div class="flex gap-2">
                    <ExportMenu :href="route('board.members.export')" :label="t('export')" />
                    <IconButton v-if="selectedTermId && can('board', 'add')" icon="plus" :label="t('add_member')" variant="primary" @click="openCreate" />
                </div>
            </div>

            <!-- Organigram -->
            <section class="card p-4 sm:p-6">
                <div class="flex flex-col items-center">
                    <!-- Top row -->
                    <div class="flex w-full flex-wrap justify-center gap-3">
                        <div v-for="n in topRow" :key="n.key" class="w-full max-w-[16rem]">
                            <OrgNode :member="n.member" :label="roleLabel(n.role.name)" size="lg" :disabled="!n.member && !can('board', 'add')"
                                @select="n.member ? openSeat(n.member) : fillRole(n.role)" />
                        </div>
                    </div>

                    <template v-if="bureauRow.length">
                        <span v-if="topRow.length" class="h-6 w-px bg-slate-300 dark:bg-slate-700"></span>
                        <!-- Bureau row: a bar joins the boxes, each hangs from it -->
                        <div class="relative w-full pt-4">
                            <span v-if="bureauRow.length > 1" class="absolute inset-x-[15%] top-0 hidden h-px bg-slate-300 sm:block dark:bg-slate-700"></span>
                            <div class="flex flex-wrap justify-center gap-3">
                                <div v-for="n in bureauRow" :key="n.key" class="relative w-full max-w-[15rem]">
                                    <span class="absolute -top-4 start-1/2 hidden h-4 w-px bg-slate-300 sm:block dark:bg-slate-700"></span>
                                    <OrgNode :member="n.member" :label="roleLabel(n.role.name)" :disabled="!n.member && !can('board', 'add')"
                                        @select="n.member ? openSeat(n.member) : fillRole(n.role)" />
                                </div>
                            </div>
                        </div>
                    </template>

                    <template v-if="committeeBoxes.length">
                        <span class="h-6 w-px bg-slate-300 dark:bg-slate-700"></span>
                        <div class="relative w-full pt-4">
                            <span v-if="committeeBoxes.length > 1" class="absolute inset-x-[10%] top-0 hidden h-px bg-slate-300 sm:block dark:bg-slate-700"></span>
                            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                <div v-for="box in committeeBoxes" :key="box.committee.id" class="relative rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700">
                                    <span class="absolute -top-4 start-1/2 hidden h-4 w-px bg-slate-300 sm:block dark:bg-slate-700"></span>
                                    <p class="mb-2 text-center text-sm font-bold text-slate-800 dark:text-slate-100">{{ box.committee.name }}</p>
                                    <div class="space-y-2">
                                        <OrgNode v-for="(m, i) in box.head" :key="`h${i}`" :member="m" :label="t('board.position.head')"
                                            :disabled="!m && !can('board', 'add')"
                                            @select="m ? openSeat(m, box.committee, 'head') : fillSeat(box.committee, 'head')" />
                                        <div class="grid grid-cols-2 gap-2">
                                            <OrgNode v-for="(m, i) in box.deputies" :key="`d${i}`" :member="m" :label="t('board.position.deputy')" size="sm"
                                                :disabled="!m && !can('board', 'add')"
                                                @select="m ? openSeat(m, box.committee, 'deputy') : fillSeat(box.committee, 'deputy')" />
                                        </div>
                                        <div class="border-t border-slate-200 pt-2 dark:border-slate-700">
                                            <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('board.position.members') }}</p>
                                            <div class="space-y-1.5">
                                                <OrgNode v-for="m in box.members" :key="m.id" :member="m" :label="t('board.position.member')" size="sm"
                                                    @select="openSeat(m, box.committee, 'member')" />
                                                <OrgNode v-if="can('board', 'add')" :label="t('board.add_committee_member')" size="sm" @select="fillSeat(box.committee, 'member')" />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                    <p v-else class="mt-6 text-center text-xs text-slate-400">
                        {{ t('board.no_committees') }}
                        <Link v-if="can('board_committees', 'view')" :href="route('board-committees.index')" class="font-semibold text-primary-600 hover:underline">{{ t('board_committees') }}</Link>
                    </p>
                </div>
            </section>

            <!-- Other members: member-level role, no committee -->
            <section v-if="others.length" class="space-y-2">
                <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('board.other_members') }}</h2>
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <OrgNode v-for="m in others" :key="m.id" :member="m" :label="roleLabel(m.role)" size="sm" @select="openSeat(m)" />
                </div>
            </section>

            <!-- Former members -->
            <section v-if="former.length" class="space-y-2">
                <button type="button" @click="showFormer = !showFormer" class="flex items-center gap-1 text-sm font-bold text-slate-500 hover:text-slate-700 dark:text-slate-400">
                    <Icon name="chevron" class="h-4 w-4 transition-transform" :class="showFormer ? 'rotate-90' : 'rtl:rotate-180'" />
                    {{ t('board.former_members') }} ({{ former.length }})
                </button>
                <div v-if="showFormer" class="grid gap-2 opacity-70 sm:grid-cols-2 lg:grid-cols-4">
                    <OrgNode v-for="m in former" :key="m.id" :member="m" :label="roleLabel(m.role)" size="sm" @select="openSeat(m)" />
                </div>
            </section>
        </div>

        <!-- Player picker: fills an empty seat -->
        <Teleport to="body">
            <div v-if="picker" class="fixed inset-0 z-[100] flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm sm:items-center" @click.self="picker = null">
                <div class="my-auto w-full max-w-md space-y-3 rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="truncate text-base font-bold text-slate-900 dark:text-slate-100">{{ picker.title }}</h2>
                        <button type="button" @click="picker = null" class="text-slate-400 hover:text-slate-600"><Icon name="xcircle" /></button>
                    </div>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-slate-400"><Icon name="search" /></span>
                        <input v-model="pickerSearch" :placeholder="t('search_for_member')" autocomplete="off" autofocus
                            class="w-full rounded-lg border-slate-200 bg-white ps-9 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <p v-if="pickerError" class="text-xs text-rose-500">{{ pickerError }}</p>
                    <ul class="max-h-72 overflow-auto rounded-lg ring-1 ring-slate-100 dark:ring-slate-800">
                        <li v-for="p in pickerPlayers" :key="p.id">
                            <button type="button" :disabled="pickerBusy" @click="picker.onPick(p)"
                                class="flex w-full items-center gap-2 px-3 py-2 text-start text-sm hover:bg-primary-50 disabled:opacity-50 dark:hover:bg-primary-500/10">
                                <span class="min-w-0 flex-1 truncate font-medium text-slate-800 dark:text-slate-100">{{ p.name }}</span>
                                <span v-if="onBoard.has(p.id)" class="shrink-0 rounded-full bg-primary-100 px-2 py-0.5 text-[10px] font-bold text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">{{ t('board.on_board') }}</span>
                                <span class="shrink-0 font-mono text-xs text-slate-400">{{ p.membership_id }}</span>
                            </button>
                        </li>
                        <li v-if="!pickerPlayers.length" class="px-3 py-6 text-center text-xs text-slate-400">{{ t('no_results') }}</li>
                    </ul>
                </div>
            </div>
        </Teleport>

        <!-- Seat menu: a filled seat -->
        <Teleport to="body">
            <div v-if="selected" class="fixed inset-0 z-[100] flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm sm:items-center" @click.self="selected = null">
                <div class="my-auto w-full max-w-sm space-y-4 rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900">
                    <div class="flex items-start gap-3">
                        <span class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-to-br from-primary-500 to-primary-600 text-xl font-bold text-white">
                            <img v-if="selected.member.photo_url" :src="selected.member.photo_url" :alt="selected.member.name" class="h-full w-full object-cover" />
                            <span v-else>{{ (selected.member.name || '?').charAt(0).toUpperCase() }}</span>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-base font-bold text-slate-900 dark:text-slate-100">{{ selected.member.name }}</p>
                            <p class="text-xs font-semibold text-primary-600 dark:text-primary-400">{{ roleLabel(selected.member.role) }}<span v-if="selected.member.status !== 'active'"> · {{ t(selected.member.status) }}</span></p>
                            <p v-if="selected.member.phone" dir="ltr" class="mt-0.5 truncate text-start font-mono text-xs text-slate-500 dark:text-slate-400">{{ formatPhone(selected.member.phone) }}</p>
                            <p v-if="selected.member.email" class="truncate text-xs text-slate-500 dark:text-slate-400">{{ selected.member.email }}</p>
                        </div>
                        <button type="button" @click="selected = null" class="text-slate-400 hover:text-slate-600"><Icon name="xcircle" /></button>
                    </div>

                    <p v-if="selected.member.committees?.length" class="flex flex-wrap gap-1">
                        <span v-for="c in selected.member.committees" :key="c.id" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            {{ committeeName(c.id) }} · {{ t(`board.position.${c.pivot.position}`) }}
                        </span>
                    </p>

                    <!-- Position in this committee -->
                    <div v-if="selected.committee && can('board', 'edit')" class="space-y-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ selected.committee.name }}</p>
                        <div class="grid grid-cols-3 gap-1.5">
                            <button v-for="pos in ['head', 'deputy', 'member']" :key="pos" type="button" @click="pos !== selected.position && changePosition(pos)"
                                class="rounded-lg px-2 py-1.5 text-xs font-bold transition-colors"
                                :class="pos === selected.position ? 'bg-primary-600 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700'">
                                {{ t(`board.position.${pos}`) }}
                            </button>
                        </div>
                        <p v-if="seatError" class="text-xs text-rose-500">{{ seatError }}</p>
                    </div>

                    <div class="flex flex-wrap justify-end gap-2">
                        <IconButton v-if="selected.committee && can('board', 'delete')" icon="logout" :label="t('board.leave_committee')" variant="danger" @click="leaveCommittee" />
                        <IconButton v-if="can('board', 'edit')" icon="pencil" :label="t('edit')" @click="openEdit(selected.member)" />
                        <IconButton v-if="can('board', 'delete')" icon="trash" :label="t('board.remove_from_board')" variant="danger" @click="removeTarget = selected.member" />
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Member form (add / edit role, term, status) -->
        <Teleport to="body">
            <div v-if="showForm" class="fixed inset-0 z-[100] flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm sm:items-center" @click.self="showForm = false">
                <form @submit.prevent="submit" class="my-auto w-full max-w-lg space-y-4 rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">{{ editingId ? t('edit') : t('add_member') }}</h2>
                        <button type="button" @click="showForm = false" class="text-slate-400 hover:text-slate-600"><Icon name="xcircle" /></button>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <!-- Searchable player picker -->
                        <div class="relative block text-sm sm:col-span-3">
                            <span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('player') }}</span>
                            <div class="flex items-center gap-2">
                                <div class="relative flex-1">
                                    <input v-model="playerSearch" @focus="showPlayerList = true" @input="showPlayerList = true; form.player_id = null"
                                        :placeholder="t('search_for_member')" autocomplete="off"
                                        class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    <ul v-if="showPlayerList && filteredPlayers.length" class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-800">
                                        <li v-for="p in filteredPlayers" :key="p.id" @click="pickPlayer(p)"
                                            class="cursor-pointer px-3 py-1.5 text-sm hover:bg-primary-50 dark:hover:bg-primary-500/10">
                                            <span class="font-medium text-slate-800 dark:text-slate-100">{{ p.name }}</span>
                                            <span class="ms-2 font-mono text-xs text-slate-400">{{ p.membership_id }}</span>
                                        </li>
                                    </ul>
                                </div>
                                <button v-if="form.player_id" type="button" @click="clearPlayer" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-rose-500 dark:hover:bg-slate-800"><Icon name="xcircle" /></button>
                            </div>
                            <p class="mt-1 text-xs text-slate-400">{{ t('pick_player_hint') }}</p>
                            <span v-if="form.errors.player_id" class="mt-1 block text-xs text-rose-500">{{ form.errors.player_id }}</span>
                        </div>

                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('role') }}</span>
                            <select v-model="form.role" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                                <option v-for="r in roles" :key="r.id" :value="r.name">{{ r.label || t(r.name) }}</option>
                            </select></label>
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('term') }}</span>
                            <select v-model="form.board_term_id" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                                <option v-for="tm in terms" :key="tm.id" :value="tm.id">{{ tm.name }}</option>
                            </select></label>
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('status') }}</span>
                            <select v-model="form.status" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                                <option value="active">{{ t('active') }}</option><option value="former">{{ t('former') }}</option>
                            </select></label>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="showForm = false" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                        <button :disabled="form.processing || !form.player_id" class="rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('save') }}</button>
                    </div>
                </form>
            </div>
        </Teleport>

        <ConfirmModal :show="!!removeTarget" :title="t('board.remove_from_board')" :message="removeTarget ? `${removeTarget.name} — ${t('confirm_delete')}` : ''"
            @confirm="remove" @cancel="removeTarget = null" />

        <!-- Term modal -->
        <Teleport to="body">
            <div v-if="showTermForm" class="fixed inset-0 z-[100] flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm sm:items-center" @click.self="showTermForm = false">
                <form @submit.prevent="submitTerm" class="my-auto w-full max-w-md space-y-4 rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">{{ editingTermId ? t('edit_term') : t('new_term') }}</h2>
                        <button type="button" @click="showTermForm = false" class="text-slate-400 hover:text-slate-600"><Icon name="xcircle" /></button>
                    </div>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('name') }}</span>
                        <input v-model="termForm.name" required :placeholder="'2024–2028'" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                        <span v-if="termForm.errors.name" class="mt-1 block text-xs text-rose-500">{{ termForm.errors.name }}</span></label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('term_start') }}</span>
                            <input v-model="termForm.start_date" type="date" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                            <span v-if="termForm.errors.start_date" class="mt-1 block text-xs text-rose-500">{{ termForm.errors.start_date }}</span></label>
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('term_end') }}</span>
                            <input v-model="termForm.end_date" type="date" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" />
                            <span v-if="termForm.errors.end_date" class="mt-1 block text-xs text-rose-500">{{ termForm.errors.end_date }}</span></label>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input v-model="termForm.is_current" type="checkbox" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500" />
                        {{ t('set_as_current_term') }}
                    </label>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="showTermForm = false" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                        <button :disabled="termForm.processing" class="rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('save') }}</button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
