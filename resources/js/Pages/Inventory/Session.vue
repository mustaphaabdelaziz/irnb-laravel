<script setup>
import { reactive, computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatTile from '@/Components/Dashboard/StatTile.vue';
import Icon from '@/Components/Icon.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import IconButton from '@/Components/IconButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { stateLabel as stateLabelOf } from '@/lib/stateLabel';
import { useCan } from '@/Composables/useCan';
import { initialFound, unitTotals, clampFound } from '@/lib/stocktake';

const props = defineProps({
    session: { type: Object, required: true },
    conditions: { type: Array, default: () => [] },
    storageLocations: { type: Array, default: () => [] },
    players: { type: Array, default: () => [] },
});
const { t, locale } = useI18n();
const { can } = useCan();
const stateLabel = (v) => stateLabelOf(t, v);

const isOpen = computed(() => props.session.status === 'in_progress');
// Counting and participants need the inventory edit right as well as an open session.
const canCount = computed(() => isOpen.value && can('inventory', 'edit'));

// Editable state per line.
const state = reactive({});
props.session.items.forEach((line) => {
    state[line.id] = {
        qty: initialFound(line),
        actual_condition: line.actual_condition || line.expected_condition || '',
        actual_location: line.actual_location ?? line.expected_location ?? '',
        note: line.note || '',
    };
});

const participantPick = ref('');
const participantLabel = (name, membershipId, fallbackId) => {
    const full = (name || '').trim() || `#${fallbackId}`;
    return membershipId ? `${full} — ${membershipId}` : full;
};

const participants = ref(
    (props.session.participants || []).map((p) => ({
        type: 'Player',
        id: p.participant_id,
        label: participantLabel(
            p.participant?.fullname ?? `${p.participant?.firstname ?? ''} ${p.participant?.lastname ?? ''}`,
            p.participant?.membership_id,
            p.participant_id,
        ),
    }))
);

const participantOptions = computed(() => {
    const taken = new Set(participants.value.map((p) => p.id));
    return props.players
        .map((pl) => ({ value: pl.id, label: participantLabel(pl.fullname, pl.membership_id, pl.id) }))
        .filter((o) => !taken.has(o.value));
});

function addParticipant() {
    if (!participantPick.value) return;
    const opt = participantOptions.value.find((o) => String(o.value) === String(participantPick.value));
    if (!opt) return;
    participants.value.push({ type: 'Player', id: opt.value, label: opt.label });
    participantPick.value = '';
}
function removeParticipant(idx) {
    participants.value.splice(idx, 1);
}
function saveParticipants() {
    router.post(route('inventory.participants', props.session.id), {
        participants: participants.value.map((p) => ({ type: p.type, id: p.id })),
    }, { preserveScroll: true });
}

// Group by expected location.
const groups = computed(() => {
    const map = {};
    for (const line of props.session.items) {
        const key = line.expected_location || t('unassigned');
        (map[key] ||= []).push(line);
    }
    // Sort location keys alphabetically, keeping "Unassigned" last.
    return Object.entries(map)
        .map(([location, lines]) => ({ location, lines }))
        .sort((a, b) => {
            if (a.location === t('unassigned')) return 1;
            if (b.location === t('unassigned')) return -1;
            return a.location.localeCompare(b.location);
        });
});

const totals = computed(() => unitTotals(props.session.items, state));

function markAllFound() {
    props.session.items.forEach((l) => { state[l.id].qty = l.expected_quantity; });
}
function payload() {
    return props.session.items.map((line) => {
        const qty = clampFound(state[line.id].qty, line.expected_quantity);
        return {
            id: line.id,
            found: qty > 0,
            found_quantity: qty,
            actual_condition: state[line.id].actual_condition || null,
            actual_location: state[line.id].actual_location || null,
            note: state[line.id].note || null,
        };
    });
}
function saveCounts() {
    router.put(route('inventory.counts', props.session.id), { items: payload() }, { preserveScroll: true });
}
function complete() {
    if (!window.confirm(t('complete_inventory_confirm'))) return;
    saveCountsThen(() => router.post(route('inventory.complete', props.session.id), {}, { preserveScroll: true }));
}
function saveCountsThen(cb) {
    router.put(route('inventory.counts', props.session.id), { items: payload() }, { preserveScroll: true, onSuccess: cb });
}
function fmtDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString(locale.value === 'ar' ? 'ar' : locale.value, { day: '2-digit', month: 'short', year: 'numeric' });
}

// Report discrepancies (for completed sessions).
const discrepancies = computed(() => props.session.items.filter((l) =>
    l.counted && (!l.found || (l.actual_condition && l.actual_condition !== l.expected_condition) || (l.actual_location && l.actual_location !== l.expected_location)),
));
</script>

<template>
    <Head :title="session.reference" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center gap-2">
                <Link :href="route('inventory.index')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 dark:hover:text-slate-300"><Icon name="back" /></Link>
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ session.reference }}</h1>
                <span class="rounded-full px-2.5 py-0.5 text-xs font-bold" :class="isOpen ? 'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300'">{{ t(session.status) }}</span>
                <span class="ms-auto flex gap-2">
                    <ExportMenu :href="route('inventory.export', session.id)" :label="t('export')" data-help="stk-export" />
                    <IconButton :href="route('inventory.report', session.id)" external target="_blank" icon="print" :label="t('report')" data-help="stk-report" />
                </span>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Participants -->
            <div class="card p-5">
                <p class="mb-3 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('participants') }}</p>
                <div class="flex flex-wrap gap-2">
                    <span v-for="(p, idx) in participants" :key="p.type + p.id" class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        {{ p.label }}
                        <button v-if="canCount" @click="removeParticipant(idx)" class="text-slate-400 hover:text-rose-500 dark:hover:text-rose-400">×</button>
                    </span>
                    <span v-if="!participants.length" class="text-sm text-slate-400">{{ t('no_results') }}</span>
                </div>
                <div v-if="canCount" class="mt-4 flex flex-wrap items-end gap-2">
                    <div class="min-w-[12rem] flex-1">
                        <SearchableSelect v-model="participantPick" :options="participantOptions" :placeholder="t('add_participant')" data-help="stk-participant-pick" />
                    </div>
                    <IconButton icon="plus" :label="t('add')" data-help="stk-participant-add" @click="addParticipant" />
                    <button data-help="stk-participant-save" @click="saveParticipants" class="rounded-xl bg-slate-900 px-3 py-1.5 text-sm font-bold text-white hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900">{{ t('save') }}</button>
                </div>
            </div>

            <!-- Summary -->
            <div class="grid grid-cols-3 gap-1.5 sm:gap-4">
                <StatTile :label="t('total_expected')" :value="isOpen ? totals.expected : session.total_expected" compact />
                <StatTile :label="t('found')" :value="isOpen ? totals.found : session.total_found" compact />
                <StatTile :label="t('missing')" :value="isOpen ? totals.missing : session.total_missing" compact />
            </div>

            <!-- OPEN: count sheet -->
            <template v-if="isOpen">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ fmtDate(session.session_date) }} · {{ t(session.type) }}</p>
                    <div class="flex gap-2">
                        <IconButton v-if="canCount" icon="check" :label="t('mark_all_found')" variant="success" data-help="stk-mark-all" @click="markAllFound" />
                        <button v-if="canCount" data-help="stk-save-counts" @click="saveCounts" class="rounded-xl bg-slate-900 px-3.5 py-2 text-sm font-bold text-white hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900">{{ t('save_counts') }}</button>
                        <button v-if="canCount" data-help="stk-complete" @click="complete" class="rounded-xl bg-emerald-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-emerald-700">{{ t('complete_inventory') }}</button>
                    </div>
                </div>

                <div v-if="!session.items.length" class="card py-10 text-center text-sm text-slate-400">{{ t('no_data') }}</div>

                <section v-for="grp in groups" :key="grp.location" class="card overflow-hidden">
                    <p class="border-b border-slate-100 px-5 py-2.5 text-sm font-bold text-slate-700 dark:border-slate-800 dark:text-slate-200">{{ grp.location }} <span class="font-normal text-slate-400">({{ grp.lines.length }})</span></p>
                    <div class="divide-y divide-slate-50 dark:divide-slate-800/50">
                        <div v-for="line in grp.lines" :key="line.id" :data-help="'stk-line-' + line.equipment_item_id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ line.item?.unique_identifier }}</p>
                                <p class="text-xs text-slate-400">{{ line.item?.catalog?.name }} · {{ t('expected') }}: {{ stateLabel(line.expected_condition) }}<span v-if="line.expected_location"> · {{ line.expected_location }}</span><span v-if="line.on_loan_quantity > 0"> · {{ t('inventory_sheet.on_loan', { n: line.on_loan_quantity }) }}</span></p>
                            </div>
                            <div v-if="line.expected_quantity === 1" class="flex gap-1">
                                <button :disabled="!canCount" :data-help="'stk-found-' + line.equipment_item_id" @click="state[line.id].qty = 1" class="rounded-lg px-2.5 py-1 text-xs font-bold" :class="state[line.id].qty > 0 ? 'bg-emerald-500 text-white' : 'bg-slate-100 text-slate-400 dark:bg-slate-800'">{{ t('found') }}</button>
                                <button :disabled="!canCount" :data-help="'stk-missing-' + line.equipment_item_id" @click="state[line.id].qty = 0" class="rounded-lg px-2.5 py-1 text-xs font-bold" :class="state[line.id].qty === 0 ? 'bg-rose-500 text-white' : 'bg-slate-100 text-slate-400 dark:bg-slate-800'">{{ t('missing') }}</button>
                            </div>
                            <div v-else class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                <span class="font-bold">{{ t('found') }}</span>
                                <input type="number" min="0" :max="line.expected_quantity" v-model.number="state[line.id].qty" @blur="state[line.id].qty = clampFound(state[line.id].qty, line.expected_quantity)" :disabled="!canCount" :data-help="'stk-qty-' + line.equipment_item_id" class="w-16 rounded-lg border-slate-200 py-1 text-center text-xs disabled:opacity-40 dark:border-slate-700 dark:bg-slate-800" dir="ltr">
                                <span>{{ t('inventory_sheet.of', { n: line.expected_quantity }) }}</span>
                            </div>
                            <select v-model="state[line.id].actual_condition" :data-help="'stk-condition-' + line.equipment_item_id" :disabled="!canCount || !state[line.id].qty" class="rounded-lg border-slate-200 bg-white py-1 text-xs disabled:opacity-40 dark:border-slate-700 dark:bg-slate-800">
                                <option v-for="c in conditions" :key="c" :value="c">{{ stateLabel(c) }}</option>
                            </select>
                            <select v-model="state[line.id].actual_location" :data-help="'stk-location-' + line.equipment_item_id" :disabled="!canCount || !state[line.id].qty" class="w-32 rounded-lg border-slate-200 bg-white py-1 text-xs disabled:opacity-40 dark:border-slate-700 dark:bg-slate-800">
                                <option value="">{{ t('location') }}</option>
                                <option v-for="loc in storageLocations" :key="loc" :value="loc">{{ loc }}</option>
                            </select>
                            <input v-model="state[line.id].note" maxlength="500" :placeholder="t('inventory_sheet.note_placeholder')" :disabled="!canCount" :data-help="'stk-note-' + line.equipment_item_id" class="w-full rounded-lg border-slate-200 py-1 text-xs disabled:opacity-40 sm:w-40 dark:border-slate-700 dark:bg-slate-800">
                        </div>
                    </div>
                </section>
            </template>

            <!-- COMPLETED: report -->
            <template v-else>
                <div class="card p-5">
                    <p class="mb-3 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('report') }} · {{ t('discrepancy') }}</p>
                    <div v-if="discrepancies.length" class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-xs uppercase tracking-wide text-slate-400">
                                <tr class="border-b border-slate-100 dark:border-slate-800">
                                    <th class="px-3 py-2 text-start font-semibold">{{ t('item') }}</th>
                                    <th class="px-3 py-2 text-center font-semibold">{{ t('found') }}</th>
                                    <th class="px-3 py-2 text-start font-semibold">{{ t('expected') }}</th>
                                    <th class="px-3 py-2 text-start font-semibold">{{ t('actual') }}</th>
                                    <th class="px-3 py-2 text-start font-semibold">{{ t('note') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="l in discrepancies" :key="l.id" class="border-b border-slate-50 dark:border-slate-800/50">
                                    <td class="px-3 py-2 font-medium text-slate-800 dark:text-slate-200">{{ l.item?.unique_identifier }}</td>
                                    <td class="px-3 py-2 text-center"><span :class="l.found ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-500 dark:text-rose-400'"><Icon :name="l.found ? 'check' : 'xcircle'" /></span></td>
                                    <td class="px-3 py-2 text-slate-500 dark:text-slate-400">{{ stateLabel(l.expected_condition) }}<span v-if="l.expected_location"> · {{ l.expected_location }}</span></td>
                                    <td class="px-3 py-2 text-slate-700 dark:text-slate-300">{{ stateLabel(l.actual_condition) }}<span v-if="l.actual_location"> · {{ l.actual_location }}</span></td>
                                    <td class="px-3 py-2 text-slate-500 dark:text-slate-400">{{ l.note }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="py-6 text-center text-sm text-emerald-600 dark:text-emerald-400"><Icon name="check" /> {{ t('no_data') }}</p>
                </div>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
