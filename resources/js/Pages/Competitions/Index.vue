<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import SeasonSelect from '@/Components/Competitions/SeasonSelect.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';

const { t } = useI18n();

const props = defineProps({
    competitions: Array, teams: Array, formats: Array, types: Array,
    season: Number, seasons: Array, filters: Object, manageableTeamIds: Array, canAdd: Boolean,
});

const creating = ref(false);
const form = useForm({ team_id: props.filters.team_id ?? '', season_start_year: props.season, name: '', type: 'league', game_format_id: '' });
const teamOptions = computed(() => props.manageableTeamIds === null
    ? props.teams
    : props.teams.filter((team) => props.manageableTeamIds.includes(team.value)));
const formatOptions = computed(() => {
    const team = props.teams.find((x) => x.value === Number(form.team_id));
    return team ? props.formats.filter((f) => f.branch_id === team.branch_id) : [];
});

function reload(params) {
    router.get(route('competitions.index'), { season: props.season, ...props.filters, ...params }, { preserveState: true });
}

function create() {
    form.transform((data) => ({ ...data, game_format_id: data.game_format_id || null })).post(route('competitions.store'));
}

const formClass = { W: 'bg-emerald-500', D: 'bg-slate-400', L: 'bg-rose-500' };
</script>

<template>
    <Head :title="t('competitions')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('competitions') }}</h1>
                <div class="flex flex-wrap items-center gap-3">
                    <SeasonSelect :model-value="season" :seasons="seasons" @update:model-value="reload({ season: $event })" />
                    <SearchableSelect :model-value="filters.team_id ?? ''" :options="teams" :placeholder="t('team')" class="w-56"
                        @update:model-value="reload({ team_id: $event || null })" />
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" :checked="filters.archived" class="rounded" @change="reload({ archived: $event.target.checked ? 1 : 0 })" /> {{ t('comp.show_archived') }}</label>
                    <PrimaryButton v-if="canAdd && teamOptions.length" @click="creating = !creating">{{ t('comp.new_competition') }}</PrimaryButton>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-6xl space-y-6">
            <form v-if="creating" @submit.prevent="create" class="grid gap-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800 sm:grid-cols-5">
                <label class="text-sm">{{ t('team') }}
                    <SearchableSelect v-model="form.team_id" :options="teamOptions" class="mt-1" />
                </label>
                <label class="text-sm">{{ t('name') }}
                    <TextInput v-model="form.name" class="mt-1 w-full" required />
                    <InputError :message="form.errors.name" />
                </label>
                <label class="text-sm">{{ t('comp.type') }}
                    <select v-model="form.type" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option v-for="type in types" :key="type" :value="type">{{ t(`comp.type_${type}`) }}</option>
                    </select>
                </label>
                <label class="text-sm">{{ t('comp.game_format') }}
                    <select v-model="form.game_format_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ t('comp.team_default_format') }}</option>
                        <option v-for="f in formatOptions" :key="f.id" :value="f.id">{{ f.localized_name }}</option>
                    </select>
                    <InputError :message="form.errors.game_format_id ? t(form.errors.game_format_id) : ''" />
                </label>
                <div class="flex items-end gap-2">
                    <PrimaryButton :disabled="form.processing || !form.team_id">{{ t('add') }}</PrimaryButton>
                    <SecondaryButton type="button" @click="creating = false">{{ t('cancel') }}</SecondaryButton>
                </div>
            </form>

            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3 text-start">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('team') }}</th>
                            <th class="px-4 py-3 text-center">{{ t('comp.record_short') }}</th>
                            <th class="px-4 py-3 text-center">{{ t('comp.goals_short') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('comp.form') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="c in competitions" :key="c.id" :class="{ 'opacity-60': c.archived }">
                            <td class="px-4 py-3">
                                <Link :href="route('competitions.show', c.id)" class="font-medium text-primary-700 hover:underline dark:text-primary-300">{{ c.name }}</Link>
                                <div class="text-xs text-slate-500">{{ t(`comp.type_${c.type}`) }} · {{ t('comp.games_count', { n: c.games_count }) }}</div>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ c.team }}</td>
                            <td class="px-4 py-3 text-center font-mono"><bdi dir="ltr">{{ c.record.wins }}-{{ c.record.draws }}-{{ c.record.losses }}</bdi></td>
                            <td class="px-4 py-3 text-center font-mono"><bdi dir="ltr">{{ c.record.goals_for }}:{{ c.record.goals_against }}</bdi></td>
                            <td class="px-4 py-3">
                                <div class="flex gap-1">
                                    <span v-for="(r, i) in c.record.form" :key="i" class="flex h-5 w-5 items-center justify-center rounded text-xs font-bold text-white" :class="formClass[r]">{{ t(`comp.form_${r}`) }}</span>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!competitions.length"><td colspan="5" class="px-4 py-8 text-center text-slate-500">{{ t('no_results') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
