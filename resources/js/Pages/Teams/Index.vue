<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SeasonSelect from '@/Components/Competitions/SeasonSelect.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';

const { t } = useI18n();

const props = defineProps({
    teams: Array, branches: Array, categories: Array, formats: Array,
    season: Number, seasons: Array, showArchived: Boolean, canCreate: Boolean,
});

const creating = ref(false);
const form = useForm({ branch_id: '', category_id: '', name: '', default_game_format_id: '' });
const formatsOfBranch = computed(() => props.formats.filter((f) => f.branch_id === Number(form.branch_id)));

function reload(params) {
    router.get(route('teams.index'), { season: props.season, archived: props.showArchived ? 1 : 0, ...params }, { preserveState: true });
}

function create() {
    form.post(route('teams.store'));
}
</script>

<template>
    <Head :title="t('teams')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('teams') }}</h1>
                <div class="flex items-center gap-3">
                    <SeasonSelect :model-value="season" :seasons="seasons" @update:model-value="reload({ season: $event })" />
                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" :checked="showArchived" class="rounded" @change="reload({ archived: $event.target.checked ? 1 : 0 })" />
                        {{ t('comp.show_archived') }}
                    </label>
                    <PrimaryButton v-if="canCreate" @click="creating = !creating">{{ t('comp.new_team') }}</PrimaryButton>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-6xl space-y-6">
            <form v-if="creating" @submit.prevent="create" class="grid gap-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800 sm:grid-cols-5">
                <label class="text-sm">{{ t('comp.sport') }}
                    <select v-model="form.branch_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" required @change="form.default_game_format_id = ''">
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.localized_name || b.name }}</option>
                    </select>
                </label>
                <label class="text-sm">{{ t('category') }}
                    <select v-model="form.category_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" required>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.localized_name || c.name }}</option>
                    </select>
                </label>
                <label class="text-sm">{{ t('name') }}
                    <TextInput v-model="form.name" class="mt-1 w-full" :placeholder="t('comp.team_name_hint')" required />
                    <InputError :message="form.errors.name ? t(form.errors.name) : ''" class="mt-1" />
                </label>
                <label class="text-sm">{{ t('comp.game_format') }}
                    <select v-model="form.default_game_format_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800" required>
                        <option v-for="f in formatsOfBranch" :key="f.id" :value="f.id">{{ f.localized_name }}</option>
                    </select>
                    <InputError :message="form.errors.default_game_format_id ? t(form.errors.default_game_format_id) : ''" class="mt-1" />
                    <p v-if="form.branch_id && !formatsOfBranch.length" class="mt-1 text-xs text-amber-600">{{ t('comp.no_format_for_sport') }}</p>
                </label>
                <div class="flex items-end gap-2">
                    <PrimaryButton :disabled="form.processing">{{ t('add') }}</PrimaryButton>
                    <SecondaryButton type="button" @click="creating = false">{{ t('cancel') }}</SecondaryButton>
                </div>
            </form>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link v-for="team in teams" :key="team.id" :href="route('teams.show', { team: team.id, season })"
                    class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-primary-400 dark:ring-slate-800"
                    :class="{ 'opacity-60': team.archived }">
                    <div class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ team.name }}</div>
                    <div class="text-sm text-slate-500 dark:text-slate-400">{{ team.branch }} · {{ team.category }} · {{ team.format }}</div>
                    <div class="mt-3 flex items-center justify-between text-sm">
                        <span class="text-slate-600 dark:text-slate-300">{{ t('comp.players_count', { n: team.roster_count }) }}</span>
                        <span class="truncate text-slate-500 dark:text-slate-400">{{ team.head_coaches.join(', ') || t('comp.no_coach') }}</span>
                    </div>
                </Link>
                <p v-if="!teams.length" class="col-span-full py-10 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
