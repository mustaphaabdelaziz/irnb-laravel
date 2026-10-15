<script setup>
import PlayerBadges from '@/Components/Competitions/PlayerBadges.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { useCan } from '@/Composables/useCan.js';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({
    competition: Object, roster: Array, games: Array, record: Object, formats: Array, types: Array, suspensions: Array, canManage: Boolean,
});

const tab = ref('squad');
const confirmDelete = ref(false);

const selected = ref(props.roster.filter((r) => r.in_squad).map((r) => r.player_id));
const squadForm = useForm({ player_ids: [] });
function saveSquad() {
    squadForm.player_ids = selected.value;
    squadForm.put(route('competitions.squad.update', props.competition.id), { preserveScroll: true });
}

const rulesForm = useForm({
    name: props.competition.name, type: props.competition.type, game_format_id: props.competition.game_format_id ?? '',
    red_ban_matches: props.competition.red_ban_matches, yellow_threshold: props.competition.yellow_threshold ?? '',
    yellow_ban_matches: props.competition.yellow_ban_matches, notes: props.competition.notes ?? '', archived: props.competition.archived,
});
function saveRules() {
    rulesForm.transform((d) => ({ ...d, game_format_id: d.game_format_id || null, yellow_threshold: d.yellow_threshold || null }))
        .put(route('competitions.update', props.competition.id), { preserveScroll: true });
}

function destroy() {
    router.delete(route('competitions.destroy', props.competition.id));
}

const banForm = useForm({ player_id: '', scope: 'competition', kind: 'matches', matches_count: 1, starts_on: '', ends_on: '', reason: '' });
function addBan() {
    banForm.transform((d) => ({ ...d, matches_count: d.kind === 'matches' ? d.matches_count : null, starts_on: d.starts_on || null, ends_on: d.kind === 'dates' ? d.ends_on : null }))
        .post(route('competitions.suspensions.store', props.competition.id), { preserveScroll: true, onSuccess: () => banForm.reset() });
}
function removeBan(id) {
    router.delete(route('competitions.suspensions.destroy', [props.competition.id, id]), { preserveScroll: true });
}
const banLabel = (s) => (s.kind === 'dates'
    ? t('comp.ban.dates', { from: s.starts_on, to: s.ends_on })
    : t('comp.ban.matches', { served: s.served ?? 0, total: s.matches_count }));

const tabs = ['squad', 'games', 'record', 'suspensions', 'rules'];
const formClass = { W: 'bg-emerald-500', D: 'bg-slate-400', L: 'bg-rose-500' };
</script>

<template>
    <Head :title="competition.name" />

    <AuthenticatedLayout>
        <template #header>
            <Link :href="route('competitions.index', { season: competition.season_start_year })" class="text-sm text-primary-600 hover:underline">{{ t('competitions') }}</Link>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ competition.name }}</h1>
            <div class="text-sm text-slate-500 dark:text-slate-400">
                <Link :href="route('teams.show', { team: competition.team.id, season: competition.season_start_year })" class="hover:underline">{{ competition.team.name }} · {{ competition.team.category }}</Link>
                · {{ t(`comp.type_${competition.type}`) }} · {{ competition.season_label }} · {{ competition.format }}
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-4">
            <nav class="flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-800">
                <button v-for="key in tabs" :key="key" type="button" @click="tab = key" class="-mb-px border-b-2 px-3 py-2 text-sm"
                    :class="tab === key ? 'border-primary-600 font-semibold text-primary-700 dark:text-primary-300' : 'border-transparent text-slate-500 dark:text-slate-400'">
                    {{ t(`comp.tab_${key}`) }}
                </button>
            </nav>

            <section v-if="tab === 'squad'" class="space-y-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <p class="text-sm text-slate-500">{{ t('comp.squad_help', { n: selected.length }) }}</p>
                <div class="grid gap-1 sm:grid-cols-2">
                    <label v-for="r in roster" :key="r.player_id" class="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-slate-50 dark:hover:bg-slate-800">
                        <input v-model="selected" :value="r.player_id" :disabled="!canManage" type="checkbox" class="rounded" />
                        <span class="w-6 text-end font-mono text-slate-400">{{ r.shirt_number }}</span>
                        <span class="font-medium">{{ r.name }}</span>
                        <PlayerBadges :flags="r.flags" />
                        <span class="text-xs text-slate-400">{{ r.category }}</span>
                    </label>
                </div>
                <p v-if="!roster.length" class="text-sm text-slate-500">{{ t('comp.roster_empty') }}</p>
                <InputError :message="squadForm.errors.player_ids" />
                <PrimaryButton v-if="canManage" :disabled="squadForm.processing" @click="saveSquad">{{ t('save') }}</PrimaryButton>
            </section>

            <section v-if="tab === 'games'" class="space-y-2 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <Link v-for="g in games" :key="g.id" :href="route('games.show', g.id)" class="flex items-center justify-between rounded-lg px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-800">
                    <span><bdi dir="ltr" class="font-mono text-sm">{{ g.date }}</bdi> · {{ g.opponent }} ({{ t(`comp.side_${g.side}`) }})<span v-if="g.round_label" class="text-slate-400"> · {{ g.round_label }}</span></span>
                    <span class="text-sm text-slate-500">
                        <bdi v-if="g.status === 'played'" dir="ltr">{{ g.our_score }} – {{ g.their_score }}</bdi>
                        <template v-else>{{ t(`comp.status_${g.status}`) }}</template>
                    </span>
                </Link>
                <p v-if="!games.length" class="text-sm text-slate-500">{{ t('no_results') }}</p>
                <Link v-if="canManage" :href="route('games.index', { team_id: competition.team.id, competition_id: competition.id, create: 1 })" class="inline-block text-sm text-primary-600 hover:underline">{{ t('comp.new_game') }} →</Link>
            </section>

            <section v-if="tab === 'record'" class="grid gap-4 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800 sm:grid-cols-4">
                <div v-for="key in ['played', 'wins', 'draws', 'losses', 'goals_for', 'goals_against']" :key="key" class="text-center">
                    <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ record[key] }}</div>
                    <div class="text-xs uppercase text-slate-500">{{ t(`comp.record_${key}`) }}</div>
                </div>
                <div class="text-center sm:col-span-2">
                    <div class="flex justify-center gap-1">
                        <span v-for="(r, i) in record.form" :key="i" class="flex h-7 w-7 items-center justify-center rounded font-bold text-white" :class="formClass[r]">{{ t(`comp.form_${r}`) }}</span>
                    </div>
                    <div class="mt-1 text-xs uppercase text-slate-500">{{ t('comp.form') }}</div>
                </div>
            </section>

            <section v-if="tab === 'suspensions'" class="space-y-4 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="text-xs uppercase text-slate-500"><tr>
                        <th class="py-1 text-start">{{ t('comp.player') }}</th><th class="py-1 text-start">{{ t('comp.ban.cause') }}</th>
                        <th class="py-1 text-start">{{ t('comp.ban.length') }}</th><th class="py-1 text-start">{{ t('status') }}</th><th class="w-10"></th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="s in suspensions" :key="s.id" :class="{ 'opacity-60': !s.active }">
                            <td class="py-1 font-medium">{{ s.player }}</td>
                            <td class="py-1">
                                <template v-if="s.source === 'auto'">{{ t(`comp.flag.cause_${s.cause}`) }}<span v-if="s.trigger" class="text-xs text-slate-500"> · <bdi dir="ltr">{{ s.trigger.date }}</bdi> {{ s.trigger.opponent }}</span></template>
                                <template v-else>{{ s.reason || t('comp.ban.manual') }}</template>
                                <span v-if="s.scope === 'all'" class="ms-1 rounded bg-slate-100 px-1 text-xs dark:bg-slate-800">{{ t('comp.ban.all_competitions') }}</span>
                            </td>
                            <td class="py-1"><bdi dir="ltr">{{ banLabel(s) }}</bdi></td>
                            <td class="py-1">{{ s.active ? t('comp.ban.active') : t('comp.ban.served') }}</td>
                            <td class="py-1 text-end"><IconButton v-if="canManage && s.source === 'manual'" icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="removeBan(s.id)" /></td>
                        </tr>
                        <tr v-if="!suspensions.length"><td colspan="5" class="py-6 text-center text-slate-500">{{ t('comp.ban.none') }}</td></tr>
                    </tbody>
                </table>

                <form v-if="canManage" class="grid gap-3 border-t border-slate-100 pt-4 dark:border-slate-800 sm:grid-cols-3" @submit.prevent="addBan">
                    <h3 class="text-sm font-semibold sm:col-span-3">{{ t('comp.ban.add_manual') }}</h3>
                    <label class="text-sm">{{ t('comp.player') }}
                        <select v-model="banForm.player_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" required>
                            <option v-for="r in roster" :key="r.player_id" :value="r.player_id">{{ r.name }}</option>
                        </select>
                        <InputError :message="banForm.errors.player_id ? t(banForm.errors.player_id) : ''" />
                    </label>
                    <label class="text-sm">{{ t('comp.ban.scope') }}
                        <select v-model="banForm.scope" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="competition">{{ t('comp.ban.this_competition') }}</option>
                            <option value="all">{{ t('comp.ban.all_competitions') }}</option>
                        </select>
                    </label>
                    <label class="text-sm">{{ t('comp.ban.kind') }}
                        <select v-model="banForm.kind" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="matches">{{ t('comp.ban.kind_matches') }}</option>
                            <option value="dates">{{ t('comp.ban.kind_dates') }}</option>
                        </select>
                    </label>
                    <label v-if="banForm.kind === 'matches'" class="text-sm">{{ t('comp.ban.matches_count') }}
                        <TextInput v-model.number="banForm.matches_count" type="number" min="1" class="mt-1 w-full" />
                        <InputError :message="banForm.errors.matches_count" />
                    </label>
                    <label class="text-sm">{{ t('comp.ban.starts_on') }}
                        <TextInput v-model="banForm.starts_on" type="date" class="mt-1 w-full" :required="banForm.kind === 'dates'" />
                        <InputError :message="banForm.errors.starts_on" />
                    </label>
                    <label v-if="banForm.kind === 'dates'" class="text-sm">{{ t('comp.ban.ends_on') }}
                        <TextInput v-model="banForm.ends_on" type="date" class="mt-1 w-full" required />
                        <InputError :message="banForm.errors.ends_on" />
                    </label>
                    <label class="text-sm sm:col-span-2">{{ t('comp.ban.reason') }} <TextInput v-model="banForm.reason" class="mt-1 w-full" /></label>
                    <div class="flex items-end"><PrimaryButton :disabled="banForm.processing">{{ t('add') }}</PrimaryButton></div>
                </form>
            </section>

            <section v-if="tab === 'rules'" class="space-y-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">{{ t('name') }} <TextInput v-model="rulesForm.name" :disabled="!canManage" class="mt-1 w-full" /></label>
                    <label class="text-sm">{{ t('comp.type') }}
                        <select v-model="rulesForm.type" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="type in types" :key="type" :value="type">{{ t(`comp.type_${type}`) }}</option>
                        </select>
                    </label>
                    <label class="text-sm">{{ t('comp.game_format') }}
                        <select v-model="rulesForm.game_format_id" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="">{{ t('comp.team_default_format') }}</option>
                            <option v-for="f in formats" :key="f.id" :value="f.id">{{ f.localized_name }}</option>
                        </select>
                        <InputError :message="rulesForm.errors.game_format_id ? t(rulesForm.errors.game_format_id) : ''" />
                    </label>
                </div>
                <h3 class="pt-2 text-sm font-semibold">{{ t('comp.suspension_rules') }}</h3>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">{{ t('comp.red_ban_matches') }} <TextInput v-model.number="rulesForm.red_ban_matches" :disabled="!canManage" type="number" min="0" class="mt-1 w-full" /></label>
                    <label class="text-sm">{{ t('comp.yellow_threshold') }} <TextInput v-model.number="rulesForm.yellow_threshold" :disabled="!canManage" type="number" min="1" class="mt-1 w-full" :placeholder="t('comp.yellow_threshold_none')" /></label>
                    <label class="text-sm">{{ t('comp.yellow_ban_matches') }} <TextInput v-model.number="rulesForm.yellow_ban_matches" :disabled="!canManage" type="number" min="0" class="mt-1 w-full" /></label>
                </div>
                <p class="text-xs text-slate-500">{{ t('comp.suspension_rules_help') }}</p>
                <label class="block text-sm">{{ t('notes') }}
                    <textarea v-model="rulesForm.notes" :disabled="!canManage" rows="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
                </label>
                <label class="flex items-center gap-2 text-sm"><input v-model="rulesForm.archived" :disabled="!canManage" type="checkbox" class="rounded" /> {{ t('archived') }}</label>
                <div class="flex justify-between">
                    <PrimaryButton v-if="canManage" :disabled="rulesForm.processing" @click="saveRules">{{ t('save') }}</PrimaryButton>
                    <SecondaryButton v-if="canManage && can('competitions', 'delete')" @click="confirmDelete = true">{{ t('delete') }}</SecondaryButton>
                </div>
            </section>
        </div>

        <ConfirmModal :show="confirmDelete" :message="t('comp.delete_competition_confirm')" @confirm="destroy" @cancel="confirmDelete = false" />
    </AuthenticatedLayout>
</template>
