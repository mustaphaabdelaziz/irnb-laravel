<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputError from '@/Components/InputError.vue';
import PitchBackground from '@/Components/Competitions/PitchBackground.vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';

const { t } = useI18n();
const props = defineProps({ gameId: Number, lineup: Object, canManage: Boolean });

// Local edit state, rebuilt from the server's line-up after each successful save
// (onSuccess page props; see Games/Show.vue): saved numbers and benched players show as stored.
const initialFormation = (lineup) => lineup.formation_id ?? lineup.formations[0]?.id ?? null;
const fromLineup = (lineup) => lineup.players.map((p) => ({ ...p }));
const formationId = ref(initialFormation(props.lineup));
const formation = computed(() => props.lineup.formations.find((f) => f.id === formationId.value) ?? null);
const slots = computed(() => formation.value?.slots ?? []);
const rows = ref(fromLineup(props.lineup));

const starterAt = (slot) => rows.value.find((r) => r.role === 'starter' && r.slot === slot) ?? null;
const starters = computed(() => rows.value.filter((r) => r.role === 'starter').sort((a, b) => (a.slot ?? 99) - (b.slot ?? 99)));
const bench = computed(() => rows.value.filter((r) => r.role !== 'starter'));
const find = (id) => rows.value.find((r) => r.player_id === id);

// Where a spot's dot sits: the player's dragged position for this match, else the slot's.
function dot(slot, index) {
    const r = starterAt(index);
    return { x: r?.pos_x ?? slot.x, y: r?.pos_y ?? slot.y };
}

function toBench(r) {
    Object.assign(r, { role: 'bench', slot: null, pos_x: null, pos_y: null, is_captain: false, is_goalkeeper: false });
}

function changeFormation(id) {
    formationId.value = id;
    const count = slots.value.length;
    rows.value.forEach((r) => {
        r.pos_x = null;
        r.pos_y = null;
        if (r.role === 'starter' && (r.slot === null || r.slot >= count)) toBench(r);
    });
}

// Drag and drop (HTML5): names from the bench, or dots on the pitch.
const dragging = ref(null);
function startDrag(event, playerId) {
    if (!props.canManage) return;
    dragging.value = playerId;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(playerId));
}
function endDrag() {
    dragging.value = null;
}
function take() {
    const id = dragging.value;
    dragging.value = null;
    return id === null ? null : find(id);
}
// Shared by drag-and-drop and the select/button fallbacks.
function placeOnSlot(mover, index) {
    if (!props.canManage || !mover || index === null || index === undefined || index === '') return;
    index = Number(index);
    if (!Number.isInteger(index) || index < 0 || index >= slots.value.length) return;
    const occupant = starterAt(index);
    if (occupant && occupant.player_id === mover.player_id) return;
    if (occupant) {
        if (mover.role === 'starter') Object.assign(occupant, { slot: mover.slot, pos_x: null, pos_y: null });
        else toBench(occupant);
    }
    Object.assign(mover, { role: 'starter', slot: index, pos_x: null, pos_y: null });
}
function sendToBench(row) {
    if (!props.canManage || !row) return;
    toBench(row);
}
function dropOnSlot(index) {
    placeOnSlot(take(), index);
}
function dropOnBench() {
    sendToBench(take());
}
function dropOnPitch(event) {
    const mover = take();
    if (!props.canManage || !mover || mover.role !== 'starter') return;
    const box = event.currentTarget.getBoundingClientRect();
    const clamp = (v) => Math.max(0, Math.min(100, Math.round(v)));
    mover.pos_x = clamp(((event.clientX - box.left) / box.width) * 100);
    mover.pos_y = clamp((1 - (event.clientY - box.top) / box.height) * 100);
}

function autoFill() {
    slots.value.forEach((slot, i) => {
        if (starterAt(i)) return;
        // Injured players are never picked for their usual spot; the second pass only falls back to them.
        const match = bench.value.find((r) => !r.injured && r.usual_position && r.usual_position.toUpperCase() === String(slot.label).toUpperCase());
        if (match) Object.assign(match, { role: 'starter', slot: i });
    });
    slots.value.forEach((slot, i) => {
        if (starterAt(i)) return;
        const pick = bench.value.find((r) => !r.injured) ?? bench.value[0];
        if (pick) Object.assign(pick, { role: 'starter', slot: i });
    });
}

const warnings = computed(() => {
    const list = [];
    if (starters.value.length < props.lineup.players_on_field) list.push(t('comp.warn.few_starters', { n: starters.value.length, max: props.lineup.players_on_field }));
    if (bench.value.length > props.lineup.max_bench) list.push(t('comp.warn.big_bench', { n: bench.value.length, max: props.lineup.max_bench }));
    return list;
});

const form = useForm({ formation_id: null, players: [] });
function save() {
    form.formation_id = formationId.value;
    form.players = rows.value.map((r) => ({
        player_id: r.player_id,
        role: r.role,
        slot: r.role === 'starter' ? r.slot : null,
        pos_x: r.role === 'starter' ? r.pos_x : null,
        pos_y: r.role === 'starter' ? r.pos_y : null,
        shirt_number: r.shirt_number === '' || r.shirt_number === null ? null : Number(r.shirt_number),
        is_captain: !!r.is_captain,
        is_goalkeeper: !!r.is_goalkeeper,
    }));
    form.put(route('games.lineup.update', props.gameId), {
        preserveScroll: true,
        onSuccess: (page) => {
            formationId.value = initialFormation(page.props.lineup);
            rows.value = fromLineup(page.props.lineup);
        },
    });
}

function setCaptain(r) {
    const value = !r.is_captain;
    rows.value.forEach((x) => { x.is_captain = false; });
    r.is_captain = value;
}
function setKeeper(r) {
    const value = !r.is_goalkeeper;
    rows.value.forEach((x) => { x.is_goalkeeper = false; });
    r.is_goalkeeper = value;
}
const isFootball = computed(() => ['football', 'futsal'].includes(props.lineup.sport));
const shortName = (name) => String(name ?? '').split(' ').slice(0, 2).join(' ');
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <label class="text-sm">{{ t('comp.formation') }}
                <!-- A format without formations gets one automatic shape whose id is null. -->
                <select :value="formationId ?? ''" :disabled="!canManage" class="ms-2 rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800"
                    @change="changeFormation($event.target.value === '' ? null : Number($event.target.value))">
                    <option v-for="f in lineup.formations" :key="f.id ?? 'default'" :value="f.id ?? ''">{{ f.id === null ? t('comp.formation_default') : f.name }}</option>
                </select>
            </label>
            <span class="text-sm text-slate-600 dark:text-slate-300">{{ t('comp.starters_count', { n: starters.length, max: lineup.players_on_field }) }} · {{ t('comp.bench_count', { n: bench.length, max: lineup.max_bench }) }}</span>
            <SecondaryButton v-if="canManage" @click="autoFill">{{ t('comp.auto_fill') }}</SecondaryButton>
        </div>
        <ul v-if="warnings.length" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            <li v-for="w in warnings" :key="w">{{ w }}</li>
        </ul>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,22rem)_1fr]">
            <!-- Pitch: coordinates stay left-to-right in every language. -->
            <div dir="ltr" class="relative mx-auto w-full max-w-sm overflow-hidden rounded-xl" style="aspect-ratio: 68 / 105"
                @dragover.prevent @drop.prevent="dropOnPitch">
                <PitchBackground :sport="lineup.sport" />
                <div v-for="(slot, i) in slots" :key="i" class="absolute -translate-x-1/2 translate-y-1/2"
                    :style="{ left: `${dot(slot, i).x}%`, bottom: `${dot(slot, i).y}%` }"
                    @dragover.prevent @drop.prevent.stop="dropOnSlot(i)">
                    <div v-if="starterAt(i)" :draggable="canManage" class="flex w-16 cursor-grab flex-col items-center" @dragstart="startDrag($event, starterAt(i).player_id)" @dragend="endDrag">
                        <span class="relative flex h-9 w-9 items-center justify-center rounded-full bg-white text-sm font-bold text-slate-900 shadow ring-2 ring-primary-600">
                            {{ starterAt(i).shirt_number ?? '·' }}
                            <span v-if="starterAt(i).is_captain" class="absolute -end-1 -top-1 rounded-full bg-amber-400 px-1 text-[10px] text-slate-900">{{ t('comp.captain_short') }}</span>
                            <span v-if="starterAt(i).is_goalkeeper" class="absolute -bottom-1 -end-1 rounded-full bg-sky-500 px-1 text-[10px] text-white">{{ t('comp.goalkeeper_short') }}</span>
                        </span>
                        <span class="mt-0.5 max-w-full truncate rounded bg-black/50 px-1 text-[10px] text-white" dir="auto">{{ shortName(starterAt(i).name) }}</span>
                    </div>
                    <div v-else class="flex h-9 w-9 items-center justify-center rounded-full border-2 border-dashed text-[10px] font-semibold text-white"
                        :class="isFootball ? 'border-white/80' : 'border-slate-800 bg-slate-900/40'">{{ slot.label }}</div>
                </div>
            </div>

            <div class="space-y-4">
                <!-- Bench / called-up list: drop a dot here to send the player to the bench. -->
                <div class="min-h-24 rounded-xl border border-dashed border-slate-300 p-3 dark:border-slate-700" @dragover.prevent @drop.prevent="dropOnBench">
                    <h3 class="mb-2 text-sm font-semibold">{{ t('comp.bench') }}</h3>
                    <div class="flex flex-wrap gap-2">
                        <div v-for="r in bench" :key="r.player_id" class="rounded-lg bg-slate-100 px-2 py-1 text-sm dark:bg-slate-800">
                            <div :draggable="canManage" class="cursor-grab" @dragstart="startDrag($event, r.player_id)" @dragend="endDrag">
                                {{ r.name }}
                                <span v-if="r.usual_position" class="text-xs text-slate-400">· {{ r.usual_position }}</span>
                                <span v-if="r.injured" class="ms-1 rounded bg-amber-100 px-1 text-xs text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">{{ t('comp.badge_injured') }}</span>
                            </div>
                            <div class="mt-1 flex items-center gap-1">
                                <input v-model="r.shirt_number" :disabled="!canManage" :aria-label="t('comp.shirt')" type="number" min="0" max="99" draggable="false"
                                    class="w-14 rounded border-slate-300 py-0.5 text-sm dark:border-slate-700 dark:bg-slate-900" @mousedown.stop />
                                <select :disabled="!canManage" :aria-label="t('comp.put_on_spot')" class="rounded border-slate-300 py-0.5 text-xs dark:border-slate-700 dark:bg-slate-900"
                                    @change="placeOnSlot(r, $event.target.value); $event.target.value = ''">
                                    <option value="">{{ t('comp.put_on_spot') }}</option>
                                    <option v-for="(slot, i) in slots" :key="i" :value="i">{{ slot.label }}</option>
                                </select>
                            </div>
                        </div>
                        <p v-if="!bench.length" class="text-sm text-slate-500">{{ t('comp.bench_empty') }}</p>
                    </div>
                </div>

                <!-- Starters: number, captain, goalkeeper. -->
                <table class="min-w-full text-sm">
                    <thead class="text-xs uppercase text-slate-500"><tr>
                        <th class="py-1 text-start">{{ t('comp.spot') }}</th><th class="py-1 text-start">{{ t('comp.player') }}</th>
                        <th class="w-20 py-1">{{ t('comp.shirt') }}</th><th class="py-1">{{ t('comp.captain_short') }}</th><th v-if="lineup.has_goalkeeper" class="py-1">{{ t('comp.goalkeeper_short') }}</th><th class="py-1"></th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="r in starters" :key="r.player_id">
                            <td class="py-1">
                                <select :value="r.slot" :disabled="!canManage" :aria-label="t('comp.choose_spot')" class="rounded border-slate-300 py-0.5 font-mono text-xs dark:border-slate-700 dark:bg-slate-800"
                                    @change="placeOnSlot(r, $event.target.value); $event.target.value = r.slot">
                                    <option v-for="(slot, i) in slots" :key="i" :value="i">{{ slot.label }}</option>
                                </select>
                            </td>
                            <td class="py-1">{{ r.name }}</td>
                            <td class="py-1"><input v-model="r.shirt_number" :disabled="!canManage" :aria-label="t('comp.shirt')" type="number" min="0" max="99" class="w-16 rounded border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" /></td>
                            <td class="py-1 text-center"><input type="checkbox" :checked="r.is_captain" :disabled="!canManage" :aria-label="t('comp.captain_short')" class="rounded" @change="setCaptain(r)" /></td>
                            <td v-if="lineup.has_goalkeeper" class="py-1 text-center"><input type="checkbox" :checked="r.is_goalkeeper" :disabled="!canManage" :aria-label="t('comp.goalkeeper_short')" class="rounded" @change="setKeeper(r)" /></td>
                            <td class="py-1 text-end"><button type="button" :disabled="!canManage" class="rounded bg-slate-100 px-2 py-0.5 text-xs hover:bg-slate-200 disabled:opacity-50 dark:bg-slate-800 dark:hover:bg-slate-700" @click="sendToBench(r)">{{ t('comp.to_bench') }}</button></td>
                        </tr>
                    </tbody>
                </table>
                <InputError :message="form.errors.players ? t(form.errors.players) : ''" />
                <InputError v-for="(msg, key) in form.errors" v-show="key !== 'players'" :key="key" :message="t(msg)" />
                <div class="flex flex-wrap gap-2">
                    <PrimaryButton v-if="canManage" :disabled="form.processing" @click="save">{{ t('save') }}</PrimaryButton>
                    <a :href="route('games.lineup-sheet', gameId)" target="_blank" class="self-center text-sm text-primary-600 hover:underline">{{ t('comp.lineup_sheet_pdf') }}</a>
                </div>
            </div>
        </div>
    </div>
</template>
