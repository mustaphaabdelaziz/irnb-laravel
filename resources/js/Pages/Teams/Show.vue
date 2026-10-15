<script setup>
import PlayerBadges from '@/Components/Competitions/PlayerBadges.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import IconButton from '@/Components/IconButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import SeasonSelect from '@/Components/Competitions/SeasonSelect.vue';
import StatsLeaders from '@/Components/Competitions/StatsLeaders.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { useCan } from '@/Composables/useCan.js';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({
    team: Object, season: Number, seasons: Array, roster: Array, candidates: Array, previousRosterCount: Number,
    coaches: Array, users: Array, competitions: Array, games: Array, stats: Object, formats: Array, categories: Array, canManage: Boolean, canEditTeam: Boolean,
});

const tab = ref('roster');
const confirmDelete = ref(false);

// Roster editing works on a local copy; nothing is saved until "Save".
const rows = ref(props.roster.map((r) => ({ ...r })));
const rosterForm = useForm({ season: props.season, players: [] });
const pick = ref('');
const onlyCategory = ref(true);
const onlySport = ref(true);
const pickable = computed(() => props.candidates
    .filter((c) => !rows.value.some((r) => r.player_id === c.id))
    .filter((c) => !onlyCategory.value || c.category_id === props.team.category_id)
    .filter((c) => !onlySport.value || c.in_sport)
    .map((c) => ({ value: c.id, label: c.name })));

function addPlayer(id) {
    const c = props.candidates.find((x) => x.id === Number(id));
    if (c) rows.value.push({ player_id: c.id, name: c.name, category: null, shirt_number: null, injured: false });
    pick.value = '';
}

function saveRoster() {
    rosterForm.players = rows.value.map((r) => ({ player_id: r.player_id, shirt_number: r.shirt_number === '' ? null : r.shirt_number }));
    rosterForm.put(route('teams.roster.update', props.team.id), { preserveScroll: true });
}

function copyRoster() {
    router.post(route('teams.roster.copy', props.team.id), { season: props.season }, {
        preserveScroll: true,
        onSuccess: () => { rows.value = props.roster.map((r) => ({ ...r })); },
    });
}

const coachRows = ref(props.coaches.map((c) => ({ ...c })));
const coachForm = useForm({ season: props.season, coaches: [] });
function saveCoaches() {
    coachForm.coaches = coachRows.value.filter((c) => c.user_id);
    coachForm.put(route('teams.coaches.update', props.team.id), { preserveScroll: true });
}

const teamForm = useForm({
    name: props.team.name, category_id: props.team.category_id,
    default_game_format_id: props.team.default_game_format_id, archived: props.team.archived,
});
function saveTeam() {
    teamForm.put(route('teams.update', props.team.id), { preserveScroll: true });
}

function changeSeason(season) {
    router.get(route('teams.show', props.team.id), { season });
}

function destroyTeam() {
    router.delete(route('teams.destroy', props.team.id));
}

// The Stats tab (and its export) needs games/view; the server sends no stats without it.
const tabs = computed(() => ['roster', 'coaches', 'competitions', 'games', ...(can('games', 'view') && props.stats ? ['stats'] : []), 'settings']);
</script>

<template>
    <Head :title="team.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link :href="route('teams.index', { season })" class="text-sm text-primary-600 hover:underline">{{ t('teams') }}</Link>
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ team.name }}</h1>
                    <div class="text-sm text-slate-500 dark:text-slate-400">{{ team.branch }} · {{ team.category }} · {{ team.format }}</div>
                </div>
                <SeasonSelect :model-value="season" :seasons="seasons" @update:model-value="changeSeason" />
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-4">
            <nav class="flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-800">
                <button v-for="key in tabs" :key="key" type="button" @click="tab = key"
                    class="-mb-px border-b-2 px-3 py-2 text-sm"
                    :class="tab === key ? 'border-primary-600 font-semibold text-primary-700 dark:text-primary-300' : 'border-transparent text-slate-500 dark:text-slate-400'">
                    {{ t(`comp.tab_${key}`) }}
                </button>
            </nav>

            <!-- Roster -->
            <section v-if="tab === 'roster'" class="space-y-4 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div v-if="canManage && !rows.length && previousRosterCount" class="flex items-center justify-between rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">
                    <span>{{ t('comp.copy_roster_hint', { n: previousRosterCount }) }}</span>
                    <SecondaryButton @click="copyRoster">{{ t('comp.copy_roster') }}</SecondaryButton>
                </div>

                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800 text-sm">
                    <thead>
                        <tr class="text-xs uppercase text-slate-500 dark:text-slate-400">
                            <th class="w-20 py-2 text-start">{{ t('comp.shirt') }}</th>
                            <th class="py-2 text-start">{{ t('comp.player') }}</th>
                            <th class="py-2 text-start">{{ t('category') }}</th>
                            <th class="w-12"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="(row, i) in rows" :key="row.player_id">
                            <td class="py-2">
                                <TextInput v-if="canManage" v-model.number="row.shirt_number" type="number" min="0" max="99" class="w-16" />
                                <span v-else class="font-mono">{{ row.shirt_number }}</span>
                                <InputError :message="rosterForm.errors[`players.${i}.shirt_number`]" />
                            </td>
                            <td class="py-2 font-medium text-slate-900 dark:text-slate-100">
                                {{ row.name }}
                                <PlayerBadges :flags="row.flags" class="ms-2" />
                            </td>
                            <td class="py-2 text-slate-500 dark:text-slate-400">{{ row.category }}</td>
                            <td class="py-2 text-end">
                                <IconButton v-if="canManage" icon="trash" :label="t('comp.remove')" variant="danger" plain size="sm" @click="rows.splice(i, 1)" />
                            </td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="4" class="py-6 text-center text-slate-500">{{ t('comp.roster_empty') }}</td></tr>
                    </tbody>
                </table>

                <div v-if="canManage" class="flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                    <div class="min-w-64 flex-1">
                        <label class="text-sm font-medium">{{ t('comp.add_player') }}</label>
                        <SearchableSelect v-model="pick" :options="pickable" :placeholder="t('comp.search_player')" class="mt-1" @update:model-value="addPlayer" />
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input v-model="onlyCategory" type="checkbox" class="rounded" /> {{ t('comp.only_team_category') }}</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="onlySport" type="checkbox" class="rounded" /> {{ t('comp.only_this_sport') }}</label>
                    <PrimaryButton :disabled="rosterForm.processing" @click="saveRoster">{{ t('save') }}</PrimaryButton>
                </div>
            </section>

            <!-- Coaches -->
            <section v-if="tab === 'coaches'" class="space-y-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div v-for="(c, i) in coachRows" :key="i" class="flex flex-wrap items-center gap-3">
                    <SearchableSelect v-model="c.user_id" :options="users" :disabled="!canEditTeam" class="min-w-64 flex-1" />
                    <select v-model="c.role" :disabled="!canEditTeam" class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="head">{{ t('comp.coach_head') }}</option>
                        <option value="assistant">{{ t('comp.coach_assistant') }}</option>
                    </select>
                    <IconButton v-if="canEditTeam" icon="trash" :label="t('comp.remove')" variant="danger" plain size="sm" @click="coachRows.splice(i, 1)" />
                </div>
                <p v-if="!coachRows.length" class="text-sm text-slate-500">{{ t('comp.no_coach') }}</p>
                <InputError :message="coachForm.errors.coaches" />
                <div v-if="canEditTeam" class="flex gap-2">
                    <SecondaryButton @click="coachRows.push({ user_id: '', role: 'head' })">{{ t('add') }}</SecondaryButton>
                    <PrimaryButton :disabled="coachForm.processing" @click="saveCoaches">{{ t('save') }}</PrimaryButton>
                </div>
            </section>

            <!-- Competitions -->
            <section v-if="tab === 'competitions'" class="space-y-2 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <Link v-for="c in competitions" :key="c.id" :href="route('competitions.show', c.id)" class="flex items-center justify-between rounded-lg px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-800">
                    <span class="font-medium">{{ c.name }}</span>
                    <span class="text-sm text-slate-500">{{ t(`comp.type_${c.type}`) }}<span v-if="c.archived"> · {{ t('archived') }}</span></span>
                </Link>
                <p v-if="!competitions.length" class="text-sm text-slate-500">{{ t('no_results') }}</p>
                <Link :href="route('competitions.index', { team_id: team.id, season })" class="inline-block text-sm text-primary-600 hover:underline">{{ t('competitions') }} →</Link>
            </section>

            <!-- Matches -->
            <section v-if="tab === 'games'" class="space-y-2 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <Link v-for="g in games" :key="g.id" :href="route('games.show', g.id)" class="flex items-center justify-between rounded-lg px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-800">
                    <span><bdi dir="ltr" class="font-mono text-sm">{{ g.date }}</bdi> · {{ g.opponent }} ({{ t(`comp.side_${g.side}`) }})</span>
                    <span class="text-sm text-slate-500">
                        <template v-if="g.status === 'played'"><bdi dir="ltr">{{ g.our_score }} – {{ g.their_score }}</bdi></template>
                        <template v-else>{{ t(`comp.status_${g.status}`) }}</template>
                    </span>
                </Link>
                <p v-if="!games.length" class="text-sm text-slate-500">{{ t('no_results') }}</p>
                <Link :href="route('games.index', { team_id: team.id })" class="inline-block text-sm text-primary-600 hover:underline">{{ t('games') }} →</Link>
            </section>

            <section v-if="tab === 'stats' && stats" class="space-y-4 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <span v-for="key in ['played', 'wins', 'draws', 'losses', 'goals_for', 'goals_against']" :key="key"><b>{{ stats.record[key] }}</b> {{ t(`comp.record_${key}`) }}</span>
                </div>
                <ExportMenu v-if="can('games', 'view')" :href="route('games.stats.export', { season, team_id: team.id })" :label="t('export')" :formats="['xlsx', 'csv', 'pdf']" />
                <StatsLeaders :leaders="stats.leaders" />
            </section>
            <!-- Settings -->
            <section v-if="tab === 'settings'" class="space-y-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">{{ t('name') }}
                        <TextInput v-model="teamForm.name" :disabled="!canEditTeam" class="mt-1 w-full" />
                        <InputError :message="teamForm.errors.name ? t(teamForm.errors.name) : ''" />
                    </label>
                    <label class="text-sm">{{ t('category') }}
                        <select v-model="teamForm.category_id" :disabled="!canEditTeam" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.localized_name || c.name }}</option>
                        </select>
                    </label>
                    <label class="text-sm">{{ t('comp.game_format') }}
                        <select v-model="teamForm.default_game_format_id" :disabled="!canEditTeam" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="f in formats" :key="f.id" :value="f.id">{{ f.localized_name }}</option>
                        </select>
                    </label>
                </div>
                <label class="flex items-center gap-2 text-sm"><input v-model="teamForm.archived" :disabled="!canEditTeam" type="checkbox" class="rounded" /> {{ t('archived') }}</label>
                <div class="flex justify-between">
                    <PrimaryButton v-if="canEditTeam" :disabled="teamForm.processing" @click="saveTeam">{{ t('save') }}</PrimaryButton>
                    <SecondaryButton v-if="can('teams', 'delete')" @click="confirmDelete = true">{{ t('delete') }}</SecondaryButton>
                </div>
            </section>
        </div>

        <ConfirmModal :show="confirmDelete" :message="t('comp.delete_team_confirm')" @confirm="destroyTeam" @cancel="confirmDelete = false" />
    </AuthenticatedLayout>
</template>
