<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';

const { t } = useI18n();

const props = defineProps({
    view: String, month: String, filters: Object, upcoming: Array, past: Object, monthGames: Array, sessions: Array,
    teams: Array, competitions: Array, opponents: Array, venues: Array, manageableTeamIds: Array, canAdd: Boolean, openCreate: Boolean,
});

const creating = ref(props.openCreate);
const form = useForm({
    team_id: props.filters.team_id ?? '', competition_id: props.filters.competition_id ?? '', opponent_id: '', venue_id: '',
    side: 'home', round_label: '', date: '', kickoff_time: '', meeting_time: '', meeting_place: '', notes: '', cancel_training: true,
});
const teamOptions = computed(() => props.manageableTeamIds === null
    ? props.teams
    : props.teams.filter((team) => props.manageableTeamIds.includes(team.value)));
const competitionOptions = computed(() => props.competitions.filter((c) => c.team_id === Number(form.team_id)));

function reload(params) {
    router.get(route('games.index'), { ...props.filters, view: props.view, month: props.month, ...params }, { preserveState: true });
}

function create() {
    form.transform((d) => ({
        ...d,
        competition_id: d.competition_id || null, venue_id: d.venue_id || null,
        kickoff_time: d.kickoff_time || null, meeting_time: d.meeting_time || null,
    })).post(route('games.store'));
}

function shiftMonth(delta) {
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    reload({ month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` });
}

// Monday-first month grid: leading blanks, then each day with its matches and sessions.
const days = computed(() => {
    const [y, m] = props.month.split('-').map(Number);
    const first = new Date(y, m - 1, 1);
    const count = new Date(y, m, 0).getDate();
    const blanks = (first.getDay() + 6) % 7;
    const cells = Array.from({ length: blanks }, () => null);
    for (let d = 1; d <= count; d++) {
        const date = `${props.month}-${String(d).padStart(2, '0')}`;
        cells.push({
            day: d, date,
            games: props.monthGames.filter((g) => g.date === date),
            sessions: props.sessions.filter((s) => s.date === date),
        });
    }
    return cells;
});

const statusClass = {
    scheduled: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
    played: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    postponed: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    cancelled: 'bg-slate-200 text-slate-600 line-through dark:bg-slate-800 dark:text-slate-400',
};
</script>

<template>
    <Head :title="t('games')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('games') }}</h1>
                <div class="flex flex-wrap items-center gap-2">
                    <SearchableSelect :model-value="filters.team_id ?? ''" :options="teams" :placeholder="t('team')" class="w-56"
                        @update:model-value="reload({ team_id: $event || null, competition_id: null })" />
                    <select :value="filters.status ?? ''" class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" @change="reload({ status: $event.target.value || null })">
                        <option value="">{{ t('status') }}</option>
                        <option v-for="s in ['scheduled', 'played', 'postponed', 'cancelled']" :key="s" :value="s">{{ t(`comp.status_${s}`) }}</option>
                    </select>
                    <div class="flex overflow-hidden rounded-lg ring-1 ring-slate-300 dark:ring-slate-700">
                        <button v-for="v in ['list', 'month']" :key="v" type="button" class="px-3 py-1.5 text-sm"
                            :class="view === v ? 'bg-primary-600 text-white' : 'bg-white text-slate-600 dark:bg-slate-900 dark:text-slate-300'"
                            @click="reload({ view: v })">{{ t(`comp.view_${v}`) }}</button>
                    </div>
                    <PrimaryButton v-if="canAdd && teamOptions.length" @click="creating = !creating">{{ t('comp.new_game') }}</PrimaryButton>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-6xl space-y-6">
            <form v-if="creating" @submit.prevent="create" class="grid gap-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800 sm:grid-cols-4">
                <label class="text-sm">{{ t('team') }}
                    <SearchableSelect v-model="form.team_id" :options="teamOptions" class="mt-1" @update:model-value="form.competition_id = ''" />
                    <InputError :message="form.errors.team_id" />
                </label>
                <label class="text-sm">{{ t('comp.competition') }}
                    <select v-model="form.competition_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ t('comp.friendly_no_competition') }}</option>
                        <option v-for="c in competitionOptions" :key="c.id" :value="c.id">{{ c.name }} ({{ c.season_start_year }})</option>
                    </select>
                    <InputError :message="form.errors.competition_id ? t(form.errors.competition_id) : ''" />
                </label>
                <label class="text-sm">{{ t('comp.opponent') }}
                    <SearchableSelect v-model="form.opponent_id" :options="opponents" class="mt-1" />
                    <InputError :message="form.errors.opponent_id" />
                    <Link :href="route('opponents.index')" class="text-xs text-primary-600 hover:underline">{{ t('comp.manage_opponents') }}</Link>
                </label>
                <label class="text-sm">{{ t('comp.side') }}
                    <select v-model="form.side" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option v-for="s in ['home', 'away', 'neutral']" :key="s" :value="s">{{ t(`comp.side_${s}`) }}</option>
                    </select>
                </label>
                <label class="text-sm">{{ t('date') }}
                    <TextInput v-model="form.date" type="date" class="mt-1 w-full" required />
                    <InputError :message="form.errors.date" />
                </label>
                <label class="text-sm">{{ t('comp.kickoff') }} <TextInput v-model="form.kickoff_time" type="time" class="mt-1 w-full" /></label>
                <label class="text-sm">{{ t('comp.venue') }}
                    <select v-model="form.venue_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">—</option>
                        <option v-for="v in venues" :key="v.id" :value="v.id">{{ v.name }}</option>
                    </select>
                </label>
                <label class="text-sm">{{ t('comp.round_label') }} <TextInput v-model="form.round_label" class="mt-1 w-full" :placeholder="t('comp.round_label_hint')" /></label>
                <label class="text-sm">{{ t('comp.meeting_time') }} <TextInput v-model="form.meeting_time" type="time" class="mt-1 w-full" /></label>
                <label class="text-sm sm:col-span-2">{{ t('comp.meeting_place') }} <TextInput v-model="form.meeting_place" class="mt-1 w-full" /></label>
                <label class="flex items-end gap-2 pb-2 text-sm"><input v-model="form.cancel_training" type="checkbox" class="rounded" /> {{ t('comp.cancel_training') }}</label>
                <div class="flex items-end gap-2 sm:col-span-4">
                    <PrimaryButton :disabled="form.processing || !form.team_id || !form.opponent_id">{{ t('add') }}</PrimaryButton>
                    <SecondaryButton type="button" @click="creating = false">{{ t('cancel') }}</SecondaryButton>
                </div>
            </form>

            <template v-if="view === 'list'">
                <section class="space-y-2">
                    <h2 class="text-sm font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('comp.upcoming') }}</h2>
                    <div class="divide-y divide-slate-100 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:divide-slate-800 dark:bg-slate-900 dark:ring-slate-800">
                        <Link v-for="g in upcoming" :key="g.id" :href="route('games.show', g.id)" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800">
                            <div>
                                <div class="font-medium text-slate-900 dark:text-slate-100">{{ g.team }} — {{ g.opponent }} <span class="text-sm text-slate-500">({{ t(`comp.side_${g.side}`) }})</span></div>
                                <div class="text-xs text-slate-500">{{ g.competition || t('comp.type_friendly') }}<span v-if="g.round_label"> · {{ g.round_label }}</span><span v-if="g.venue"> · {{ g.venue }}</span></div>
                            </div>
                            <div class="text-end">
                                <bdi dir="ltr" class="font-mono text-sm">{{ g.date }} {{ g.kickoff_time }}</bdi>
                                <div><span class="rounded px-1.5 py-0.5 text-xs" :class="statusClass[g.status]">{{ t(`comp.status_${g.status}`) }}</span></div>
                            </div>
                        </Link>
                        <p v-if="!upcoming.length" class="px-4 py-6 text-center text-sm text-slate-500">{{ t('no_results') }}</p>
                    </div>
                </section>

                <section class="space-y-2">
                    <h2 class="text-sm font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('comp.past') }}</h2>
                    <div class="divide-y divide-slate-100 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:divide-slate-800 dark:bg-slate-900 dark:ring-slate-800">
                        <Link v-for="g in past.data" :key="g.id" :href="route('games.show', g.id)" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800">
                            <div>
                                <div class="font-medium text-slate-900 dark:text-slate-100">{{ g.team }} — {{ g.opponent }} <span class="text-sm text-slate-500">({{ t(`comp.side_${g.side}`) }})</span></div>
                                <div class="text-xs text-slate-500">{{ g.competition || t('comp.type_friendly') }}<span v-if="g.round_label"> · {{ g.round_label }}</span></div>
                            </div>
                            <div class="text-end">
                                <bdi dir="ltr" class="font-mono text-sm">{{ g.date }}</bdi>
                                <div v-if="g.status === 'played'" class="font-mono font-bold"><bdi dir="ltr">{{ g.our_score }} – {{ g.their_score }}</bdi></div>
                                <div v-else><span class="rounded px-1.5 py-0.5 text-xs" :class="statusClass[g.status]">{{ t(`comp.status_${g.status}`) }}</span></div>
                            </div>
                        </Link>
                        <p v-if="!past.data.length" class="px-4 py-6 text-center text-sm text-slate-500">{{ t('no_results') }}</p>
                    </div>
                    <Pagination :links="past.links" />
                </section>
            </template>

            <section v-else class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="mb-3 flex items-center justify-between">
                    <SecondaryButton @click="shiftMonth(-1)">‹</SecondaryButton>
                    <span class="font-semibold"><bdi dir="ltr">{{ month }}</bdi></span>
                    <SecondaryButton @click="shiftMonth(1)">›</SecondaryButton>
                </div>
                <p v-if="!filters.team_id" class="mb-2 text-xs text-slate-500">{{ t('comp.calendar_training_hint') }}</p>
                <div class="grid grid-cols-7 gap-1 text-xs">
                    <div v-for="d in 7" :key="`h${d}`" class="py-1 text-center font-semibold uppercase text-slate-500">{{ t(`comp.weekday_${d}`) }}</div>
                    <div v-for="(cell, i) in days" :key="i" class="min-h-20 rounded border border-slate-100 p-1 dark:border-slate-800" :class="{ 'bg-slate-50 dark:bg-slate-950': !cell }">
                        <template v-if="cell">
                            <div class="text-slate-400">{{ cell.day }}</div>
                            <Link v-for="g in cell.games" :key="`g${g.id}`" :href="route('games.show', g.id)" class="mt-0.5 block truncate rounded px-1 py-0.5" :class="statusClass[g.status]">
                                <bdi dir="ltr">{{ g.kickoff_time }}</bdi> {{ g.opponent }}
                            </Link>
                            <div v-for="s in cell.sessions" :key="`s${s.id}`" class="mt-0.5 truncate rounded bg-slate-100 px-1 py-0.5 text-slate-600 dark:bg-slate-800 dark:text-slate-300" :class="{ 'line-through opacity-60': s.cancelled }">
                                {{ t('comp.training') }} <bdi dir="ltr">{{ s.time }}</bdi>
                            </div>
                        </template>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
