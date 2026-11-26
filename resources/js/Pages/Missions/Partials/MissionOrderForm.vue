<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import IconButton from '@/Components/IconButton.vue';
import Checkbox from '@/Components/Checkbox.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { localToday } from '@/lib/localDate';

const { t } = useI18n();
const props = defineProps({
    order: { type: Object, default: null },
    // MissionTravelers::options(): { value, label, description?, function } — function is the default.
    users: { type: Array, default: () => [] },
    boardMembers: { type: Array, default: () => [] },
    // null: the viewer may not read players (or teams); that picker is hidden.
    players: { type: Array, default: null },
    teams: { type: Array, default: null },
});
const isEdit = !!props.order;
const day = (value) => (value ? String(value).slice(0, 10) : '');

const form = useForm({
    order_date: isEdit ? day(props.order.order_date) : localToday(),
    destination: props.order?.destination ?? '',
    reason: props.order?.reason ?? '',
    departure_date: day(props.order?.departure_date),
    return_date: day(props.order?.return_date),
    transport: props.order?.transport ?? '',
    travelers: (props.order?.travelers ?? []).map((row) => ({
        traveler_type: row.traveler_type ?? null,
        traveler_id: row.traveler_id ?? null,
        name: row.name ?? '',
        function: row.function ?? '',
    })),
});

// Adding someone: a kind, then the person — or a typed name.
const kinds = computed(() => ['user', 'board_member', ...(props.players ? ['player'] : []), 'typed']);
const kind = ref('user');
const pick = ref('');
const lists = computed(() => ({ user: props.users, board_member: props.boardMembers, player: props.players ?? [] }));
// The same person (kind + id) is listed once.
const listed = (type, id) => form.travelers.some((row) => row.traveler_type === type && Number(row.traveler_id) === Number(id));
const pickable = computed(() => (lists.value[kind.value] ?? []).filter((option) => !listed(kind.value, option.value)));
watch(kind, () => { pick.value = ''; });

function addPicked(id) {
    const option = (lists.value[kind.value] ?? []).find((o) => String(o.value) === String(id));
    if (option && !listed(kind.value, option.value)) {
        form.travelers.push({ traveler_type: kind.value, traveler_id: option.value, name: option.label, function: option.function ?? '' });
    }
    pick.value = '';
}
function addTyped() {
    form.travelers.push({ traveler_type: null, traveler_id: null, name: '', function: '' });
}
function removeAt(index) {
    form.travelers.splice(index, 1);
}

// "Add a team": its coaches and players of the season, all ticked; untick who stays home, then add.
const team = ref('');
const teamRows = ref(null);
const teamBusy = ref(false);
const teamFailed = ref(false);
async function loadTeam() {
    if (!team.value || teamBusy.value) return;
    teamBusy.value = true;
    teamFailed.value = false;
    try {
        const { data } = await window.axios.get(route('missions.team-members', team.value));
        teamRows.value = data.travelers
            .filter((row) => !listed(row.traveler_type, row.traveler_id))
            .map((row) => ({ ...row, keep: true }));
    } catch {
        teamRows.value = null;
        teamFailed.value = true;
    } finally {
        teamBusy.value = false;
    }
}
function closeTeam() {
    teamRows.value = null;
    team.value = '';
}
function addTeam() {
    for (const row of teamRows.value.filter((r) => r.keep)) {
        if (!listed(row.traveler_type, row.traveler_id)) {
            form.travelers.push({ traveler_type: row.traveler_type, traveler_id: row.traveler_id, name: row.name, function: row.function ?? '' });
        }
    }
    closeTeam();
}

const kindLabel = (type) => t('mis.kind.' + (type ?? 'typed'));
const rowError = (index, key) => form.errors[`travelers.${index}.${key}`];
const field = 'mt-1 w-full rounded-lg border-slate-300 shadow-sm dark:border-slate-700 dark:bg-slate-900';

function submit() {
    if (isEdit) form.put(route('missions.update', props.order.id));
    else form.post(route('missions.store'));
}
</script>

<template>
    <form @submit.prevent="submit" class="mx-auto max-w-4xl space-y-6">
        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <InputLabel :value="t('mis.order_date')" required />
                    <TextInput v-model="form.order_date" type="date" required class="mt-1 w-full" />
                    <InputError :message="form.errors.order_date" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <InputLabel :value="t('mis.destination')" required />
                    <TextInput v-model="form.destination" maxlength="200" required class="mt-1 w-full" />
                    <InputError :message="form.errors.destination" class="mt-1" />
                </div>
                <div class="sm:col-span-3">
                    <InputLabel :value="t('mis.reason')" />
                    <textarea v-model="form.reason" rows="2" maxlength="1000" :class="field"></textarea>
                    <InputError :message="form.errors.reason" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('mis.departure_date')" required />
                    <TextInput v-model="form.departure_date" type="date" required class="mt-1 w-full" />
                    <InputError :message="form.errors.departure_date" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('mis.return_date')" />
                    <TextInput v-model="form.return_date" type="date" :min="form.departure_date || undefined" class="mt-1 w-full" />
                    <InputError :message="form.errors.return_date" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('mis.transport')" />
                    <TextInput v-model="form.transport" maxlength="100" class="mt-1 w-full" />
                    <InputError :message="form.errors.transport" class="mt-1" />
                </div>
            </div>
        </div>

        <div class="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('mis.travelers') }}</h2>
            <InputError :message="form.errors.travelers" />
            <p v-if="!form.travelers.length" class="text-sm text-slate-500 dark:text-slate-400">{{ t('mis.no_travelers') }}</p>

            <div v-for="(row, index) in form.travelers" :key="index"
                class="grid grid-cols-1 items-start gap-2 rounded-xl bg-slate-50 p-3 sm:grid-cols-12 dark:bg-slate-800/60">
                <span class="self-center rounded bg-primary-50 px-1.5 py-0.5 text-center text-xs font-medium text-primary-700 sm:col-span-2 dark:bg-primary-500/15 dark:text-primary-300">{{ kindLabel(row.traveler_type) }}</span>
                <div class="sm:col-span-5">
                    <TextInput v-model="row.name" maxlength="200" required :aria-label="t('name')" :placeholder="t('name')" class="w-full" />
                    <InputError :message="rowError(index, 'name') || rowError(index, 'traveler_id') || rowError(index, 'traveler_type')" class="mt-1" />
                </div>
                <div class="sm:col-span-4">
                    <TextInput v-model="row.function" maxlength="200" :aria-label="t('mis.function_label')" :placeholder="t('mis.function_label')" class="w-full" />
                    <InputError :message="rowError(index, 'function')" class="mt-1" />
                </div>
                <div class="flex justify-end sm:col-span-1">
                    <IconButton icon="trash" :label="t('remove')" variant="danger" size="sm" plain @click="removeAt(index)" />
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 border-t border-slate-100 pt-4 sm:grid-cols-3 dark:border-slate-800">
                <div>
                    <InputLabel :value="t('mis.add_person')" />
                    <select v-model="kind" :class="field">
                        <option v-for="k in kinds" :key="k" :value="k">{{ kindLabel(k) }}</option>
                    </select>
                </div>
                <div v-if="kind === 'typed'" class="flex items-end sm:col-span-2">
                    <SecondaryButton type="button" @click="addTyped">{{ t('mis.add_typed') }}</SecondaryButton>
                </div>
                <div v-else class="sm:col-span-2">
                    <InputLabel :value="t('mis.choose_person')" />
                    <SearchableSelect v-model="pick" :options="pickable" :placeholder="t('mis.choose_person')" @update:model-value="addPicked" />
                </div>
            </div>

            <div v-if="teams" class="space-y-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <InputLabel :value="t('mis.add_team')" />
                        <SearchableSelect v-model="team" :options="teams" :placeholder="t('mis.choose_team')" :disabled="teamBusy" />
                    </div>
                    <div class="flex items-end">
                        <SecondaryButton type="button" :disabled="!team || teamBusy" @click="loadTeam">{{ t('mis.add_team') }}</SecondaryButton>
                    </div>
                </div>
                <p v-if="teamFailed" class="text-xs text-rose-600 dark:text-rose-300">{{ t('mis.team_failed') }}</p>
                <div v-if="teamRows" class="space-y-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                    <p class="text-xs text-slate-600 dark:text-slate-300">{{ t('mis.team_hint') }}</p>
                    <p v-if="!teamRows.length" class="text-sm text-slate-500 dark:text-slate-400">{{ t('mis.team_empty') }}</p>
                    <label v-for="row in teamRows" :key="row.traveler_type + '-' + row.traveler_id" class="flex flex-wrap items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <Checkbox v-model:checked="row.keep" />
                        <span class="font-medium">{{ row.name }}</span>
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ row.function }}</span>
                    </label>
                    <div class="flex justify-end gap-2">
                        <SecondaryButton type="button" @click="closeTeam">{{ t('cancel') }}</SecondaryButton>
                        <PrimaryButton type="button" :disabled="!teamRows.some((r) => r.keep)" @click="addTeam">{{ t('mis.add_ticked') }}</PrimaryButton>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="isEdit ? route('missions.show', order.id) : route('missions.index')"><SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton></Link>
            <PrimaryButton :disabled="form.processing">{{ isEdit ? t('save_changes') : t('save') }}</PrimaryButton>
        </div>
    </form>
</template>
