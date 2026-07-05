<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    members: { type: Array, default: () => [] },
    terms: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    players: { type: Array, default: () => [] },
    selectedTermId: { type: [Number, String], default: null },
});
const { t } = useI18n();

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
    termForm.name = tm.name; termForm.start_date = tm.start_date || ''; termForm.end_date = tm.end_date || ''; termForm.is_current = !!tm.is_current;
    showTermForm.value = true;
}
function submitTerm() {
    const opts = { preserveScroll: true, onSuccess: () => { showTermForm.value = false; } };
    if (editingTermId.value) termForm.put(route('board.terms.update', editingTermId.value), opts);
    else termForm.post(route('board.terms.store'), opts);
}

// ---- Member search filter ----
const search = ref('');
const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.members;
    return props.members.filter((m) => [m.name, m.email, m.role].filter(Boolean).some((v) => String(v).toLowerCase().includes(q)));
});

// ---- Member form ----
const showForm = ref(false);
const editingId = ref(null);
const form = useForm({
    player_id: null, name: '', role: props.roles[0]?.name || 'member', email: '', phone: '',
    status: 'active', bio: '', sort_order: 0, board_term_id: props.selectedTermId, photo: null,
});

// Searchable player picker
const playerSearch = ref('');
const showPlayerList = ref(false);
const filteredPlayers = computed(() => {
    const q = playerSearch.value.trim().toLowerCase();
    const list = q ? props.players.filter((p) => `${p.name} ${p.membership_id}`.toLowerCase().includes(q)) : props.players;
    return list.slice(0, 30);
});
function pickPlayer(p) {
    form.player_id = p.id;
    form.name = p.name;
    playerSearch.value = p.name;
    showPlayerList.value = false;
}
function clearPlayer() {
    form.player_id = null; playerSearch.value = '';
}

function openCreate() {
    editingId.value = null;
    form.reset(); form.clearErrors();
    form.role = props.roles[0]?.name || 'member';
    form.board_term_id = props.selectedTermId;
    playerSearch.value = '';
    showForm.value = true;
}
function openEdit(m) {
    editingId.value = m.id;
    form.reset(); form.clearErrors();
    form.player_id = m.player_id; form.name = m.name; form.role = m.role; form.email = m.email || '';
    form.phone = m.phone || ''; form.status = m.status; form.bio = m.bio || '';
    form.sort_order = m.sort_order || 0; form.board_term_id = m.board_term_id || props.selectedTermId; form.photo = null;
    playerSearch.value = m.player_id ? m.name : '';
    showForm.value = true;
}
function submit() {
    const opts = { forceFormData: true, preserveScroll: true, onSuccess: () => { showForm.value = false; } };
    if (editingId.value) form.post(route('board.members.update', editingId.value), opts);
    else form.post(route('board.members.store'), opts);
}
function remove(m) {
    if (window.confirm(t('confirm_delete'))) router.delete(route('board.members.destroy', m.id), { preserveScroll: true });
}
function initial(n) { return (n || '?').charAt(0).toUpperCase(); }
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
                    <button v-if="currentTerm" @click="openTermEdit(currentTerm)" class="inline-flex items-center gap-1.5 rounded-xl bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800"><Icon name="settings" /> {{ t('edit_term') }}</button>
                    <button @click="openTermCreate" class="inline-flex items-center gap-1.5 rounded-xl bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800"><Icon name="plus" /> {{ t('new_term') }}</button>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-slate-400"><Icon name="search" /></span>
                    <input v-model="search" :placeholder="t('search')" class="w-56 rounded-xl border-slate-200 bg-white ps-9 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div class="flex gap-2">
                    <a :href="route('board.members.export')" class="inline-flex items-center gap-1.5 rounded-xl bg-white px-3.5 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800"><Icon name="download" /> {{ t('export') }}</a>
                    <button @click="openCreate" class="inline-flex items-center gap-1.5 rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700"><Icon name="plus" /> {{ t('add_member') }}</button>
                </div>
            </div>

            <!-- Member form -->
            <form v-if="showForm" @submit.prevent="submit" class="card space-y-4 p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <!-- Searchable player picker -->
                    <div class="relative block text-sm sm:col-span-2">
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
                    </div>

                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('name') }}</span>
                        <input v-model="form.name" :required="!form.player_id" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('role') }}</span>
                        <select v-model="form.role" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="r in roles" :key="r.id" :value="r.name">{{ r.label || t(r.name) }}</option>
                        </select></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('email') }}</span>
                        <input v-model="form.email" type="email" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('phone') }}</span>
                        <input v-model="form.phone" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('term') }}</span>
                        <select v-model="form.board_term_id" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="tm in terms" :key="tm.id" :value="tm.id">{{ tm.name }}</option>
                        </select></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('status') }}</span>
                        <select v-model="form.status" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="active">{{ t('active') }}</option><option value="former">{{ t('former') }}</option>
                        </select></label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('photo') }}</span>
                        <input type="file" accept="image/*" @change="form.photo = $event.target.files[0]" class="w-full text-sm text-slate-500" /></label>
                </div>
                <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('bio') }}</span>
                    <textarea v-model="form.bio" rows="2" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800"></textarea></label>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="showForm = false" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                    <button :disabled="form.processing" class="rounded-xl bg-primary-600 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 disabled:opacity-50">{{ t('save') }}</button>
                </div>
            </form>

            <!-- Members grid -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="m in filtered" :key="m.id" class="card p-4" :class="m.status === 'former' ? 'opacity-60' : ''">
                    <div class="flex items-start gap-3">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-to-br from-primary-500 to-primary-600 text-lg font-bold text-white">
                            <img v-if="m.photo_url" :src="m.photo_url" :alt="m.name" class="h-full w-full object-cover" />
                            <span v-else>{{ initial(m.name) }}</span>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-bold text-slate-900 dark:text-slate-100">{{ m.name }}</p>
                            <p class="text-xs font-semibold text-primary-600 dark:text-primary-400">{{ roleLabel(m.role) }}</p>
                            <p v-if="m.email" class="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">{{ m.email }}</p>
                        </div>
                        <div class="flex shrink-0 gap-1">
                            <button @click="openEdit(m)" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"><Icon name="settings" /></button>
                            <button @click="remove(m)" class="rounded-lg p-1.5 text-slate-300 hover:bg-rose-50 hover:text-rose-500 dark:hover:bg-rose-500/10"><Icon name="xcircle" /></button>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center justify-between text-xs text-slate-400">
                        <span v-if="m.tasks_count" class="inline-flex items-center gap-1"><Icon name="task" class="h-3.5 w-3.5" /> {{ m.tasks_count }}</span><span v-else></span>
                        <span class="inline-flex rounded-full px-2 py-0.5 font-bold" :class="m.status === 'active' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-slate-200 text-slate-500 dark:bg-slate-700'">{{ t(m.status) }}</span>
                    </div>
                </div>
            </div>
            <p v-if="!filtered.length" class="py-10 text-center text-sm text-slate-400">{{ t('no_data') }}</p>
        </div>

        <!-- Term modal -->
        <Teleport to="body">
            <div v-if="showTermForm" class="fixed inset-0 z-[100] flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm sm:items-center" @click.self="showTermForm = false">
                <form @submit.prevent="submitTerm" class="my-auto w-full max-w-md space-y-4 rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">{{ editingTermId ? t('edit_term') : t('new_term') }}</h2>
                        <button type="button" @click="showTermForm = false" class="text-slate-400 hover:text-slate-600"><Icon name="xcircle" /></button>
                    </div>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('name') }}</span>
                        <input v-model="termForm.name" required :placeholder="'2024–2028'" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('term_start') }}</span>
                            <input v-model="termForm.start_date" type="date" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                        <label class="block text-sm"><span class="mb-1 block font-medium text-slate-600 dark:text-slate-300">{{ t('term_end') }}</span>
                            <input v-model="termForm.end_date" type="date" class="w-full rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
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
