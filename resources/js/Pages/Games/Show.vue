<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { useCan } from '@/Composables/useCan.js';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({ game: Object, candidates: Array, opponents: Array, venues: Array, canManage: Boolean });

const tab = ref('info');
const confirmDelete = ref(false);

const form = useForm({
    opponent_id: props.game.opponent_id, venue_id: props.game.venue_id ?? '', side: props.game.side,
    round_label: props.game.round_label ?? '', date: props.game.date, kickoff_time: props.game.kickoff_time ?? '',
    meeting_time: props.game.meeting_time ?? '', meeting_place: props.game.meeting_place ?? '', notes: props.game.notes ?? '',
    status: props.game.status, our_score: props.game.our_score ?? '', their_score: props.game.their_score ?? '',
});

function save() {
    form.transform((d) => ({
        ...d,
        venue_id: d.venue_id || null, kickoff_time: d.kickoff_time || null, meeting_time: d.meeting_time || null,
        our_score: d.our_score === '' ? null : d.our_score, their_score: d.their_score === '' ? null : d.their_score,
    })).put(route('games.update', props.game.id), { preserveScroll: true });
}

function destroy() {
    router.delete(route('games.destroy', props.game.id));
}
</script>

<template>
    <Head :title="`${game.team.name} — ${game.opponent.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <Link :href="route('games.index')" class="text-sm text-primary-600 hover:underline">{{ t('games') }}</Link>
            <div class="flex items-center gap-3">
                <img v-if="game.opponent.logo_url" :src="game.opponent.logo_url" alt="" class="h-9 w-9 rounded object-contain" />
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">
                    {{ game.team.name }} — {{ game.opponent.name }}
                    <bdi v-if="game.status === 'played'" dir="ltr" class="ms-2 font-mono">{{ game.our_score }} – {{ game.their_score }}</bdi>
                </h1>
            </div>
            <div class="text-sm text-slate-500 dark:text-slate-400">
                <bdi dir="ltr">{{ game.date }} {{ game.kickoff_time }}</bdi> · {{ t(`comp.side_${game.side}`) }}
                · <Link v-if="game.competition" :href="route('competitions.show', game.competition.id)" class="hover:underline">{{ game.competition.name }}</Link><span v-else>{{ t('comp.type_friendly') }}</span>
                <span v-if="game.round_label"> · {{ game.round_label }}</span> · {{ game.format.name }}
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-4">
            <nav class="flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-800">
                <button v-for="key in ['info', 'callup']" :key="key" type="button" @click="tab = key" class="-mb-px border-b-2 px-3 py-2 text-sm"
                    :class="tab === key ? 'border-primary-600 font-semibold text-primary-700 dark:text-primary-300' : 'border-transparent text-slate-500 dark:text-slate-400'">
                    {{ t(`comp.tab_${key}`) }}
                </button>
            </nav>

            <section v-if="tab === 'info'" class="space-y-4 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="grid gap-3 sm:grid-cols-4">
                    <label class="text-sm">{{ t('comp.opponent') }}
                        <SearchableSelect v-model="form.opponent_id" :options="opponents" :disabled="!canManage" class="mt-1" />
                    </label>
                    <label class="text-sm">{{ t('comp.side') }}
                        <select v-model="form.side" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="s in ['home', 'away', 'neutral']" :key="s" :value="s">{{ t(`comp.side_${s}`) }}</option>
                        </select>
                    </label>
                    <label class="text-sm">{{ t('date') }} <TextInput v-model="form.date" :disabled="!canManage" type="date" class="mt-1 w-full" /></label>
                    <label class="text-sm">{{ t('comp.kickoff') }} <TextInput v-model="form.kickoff_time" :disabled="!canManage" type="time" class="mt-1 w-full" /></label>
                    <label class="text-sm">{{ t('comp.venue') }}
                        <select v-model="form.venue_id" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="">—</option>
                            <option v-for="v in venues" :key="v.id" :value="v.id">{{ v.name }}</option>
                        </select>
                    </label>
                    <label class="text-sm">{{ t('comp.round_label') }} <TextInput v-model="form.round_label" :disabled="!canManage" class="mt-1 w-full" /></label>
                    <label class="text-sm">{{ t('comp.meeting_time') }} <TextInput v-model="form.meeting_time" :disabled="!canManage" type="time" class="mt-1 w-full" /></label>
                    <label class="text-sm">{{ t('comp.meeting_place') }} <TextInput v-model="form.meeting_place" :disabled="!canManage" class="mt-1 w-full" /></label>
                </div>
                <div class="grid gap-3 sm:grid-cols-4">
                    <label class="text-sm">{{ t('status') }}
                        <select v-model="form.status" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="s in ['scheduled', 'played', 'postponed', 'cancelled']" :key="s" :value="s">{{ t(`comp.status_${s}`) }}</option>
                        </select>
                    </label>
                    <template v-if="form.status === 'played'">
                        <label class="text-sm">{{ t('comp.our_score') }}
                            <TextInput v-model.number="form.our_score" :disabled="!canManage" type="number" min="0" class="mt-1 w-full" />
                            <InputError :message="form.errors.our_score" />
                        </label>
                        <label class="text-sm">{{ t('comp.their_score') }}
                            <TextInput v-model.number="form.their_score" :disabled="!canManage" type="number" min="0" class="mt-1 w-full" />
                            <InputError :message="form.errors.their_score" />
                        </label>
                    </template>
                </div>
                <label class="block text-sm">{{ t('notes') }}
                    <textarea v-model="form.notes" :disabled="!canManage" rows="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
                </label>
                <div class="flex justify-between">
                    <PrimaryButton v-if="canManage" :disabled="form.processing" @click="save">{{ t('save') }}</PrimaryButton>
                    <SecondaryButton v-if="canManage && can('games', 'delete')" @click="confirmDelete = true">{{ t('delete') }}</SecondaryButton>
                </div>
            </section>

            <section v-if="tab === 'callup'" class="rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <p class="text-sm text-slate-500">{{ t('comp.callup_coming') }}</p>
            </section>
        </div>

        <ConfirmModal :show="confirmDelete" :message="t('are_you_sure')" @confirm="destroy" @cancel="confirmDelete = false" />
    </AuthenticatedLayout>
</template>
