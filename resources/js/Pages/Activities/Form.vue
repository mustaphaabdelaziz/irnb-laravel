<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import ParticipantPicker from '@/Components/Activities/ParticipantPicker.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    activity: { type: Object, default: null },
    types: Array,
    branches: Array,
    categories: Array,
    players: Array,
    boardMembers: Array,
});

const a = props.activity ?? {};
const form = useForm({
    title: a.title ?? '',
    activity_type_id: a.activity_type_id ?? '',
    starts_on: a.starts_on ?? '',
    ends_on: a.ends_on ?? '',
    location: a.location ?? '',
    partners: a.partners ?? '',
    branch_id: a.branch_id ?? '',
    responsible_board_member_id: a.responsible_board_member_id ?? '',
    cost: a.cost ?? '',
    beneficiaries_count: a.beneficiaries_count ?? 0,
    external_volunteers_count: a.external_volunteers_count ?? 0,
    description: a.description ?? '',
    report: a.report ?? '',
    category_ids: a.category_ids ?? [],
    player_ids: a.player_ids ?? [],
    board_member_ids: a.board_member_ids ?? [],
});

function submit() {
    const clean = (d) => ({ ...d, ends_on: d.ends_on || null, branch_id: d.branch_id || null, responsible_board_member_id: d.responsible_board_member_id || null, cost: d.cost === '' ? null : d.cost });
    if (props.activity) form.transform(clean).put(route('activities.update', props.activity.id));
    else form.transform(clean).post(route('activities.store'));
}

const field = 'mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
const label = 'text-sm font-medium text-slate-700 dark:text-slate-200';
const card = 'space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
</script>

<template>
    <Head :title="activity ? t('activities.edit') : t('activities.add')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ activity ? t('activities.edit') : t('activities.add') }}</h1>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-6">
            <section :class="card">
                <div>
                    <label :class="label">{{ t('activities.title') }}</label>
                    <TextInput v-model="form.title" class="mt-1 w-full" required />
                    <InputError :message="form.errors.title" class="mt-1" />
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label :class="label">{{ t('activities.type') }}</label>
                        <select v-model="form.activity_type_id" :class="field" required>
                            <option value="" disabled>—</option>
                            <option v-for="ty in types" :key="ty.id" :value="ty.id">{{ ty.name }}</option>
                        </select>
                        <InputError :message="form.errors.activity_type_id" class="mt-1" />
                    </div>
                    <div>
                        <label :class="label">{{ t('activities.starts_on') }}</label>
                        <input v-model="form.starts_on" type="date" :class="field" required />
                        <InputError :message="form.errors.starts_on" class="mt-1" />
                    </div>
                    <div>
                        <label :class="label">{{ t('activities.ends_on') }}</label>
                        <input v-model="form.ends_on" type="date" :min="form.starts_on" :class="field" />
                        <p class="mt-1 text-xs text-slate-500">{{ t('activities.single_day_hint') }}</p>
                        <InputError :message="form.errors.ends_on" class="mt-1" />
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label :class="label">{{ t('activities.location') }}</label>
                        <TextInput v-model="form.location" class="mt-1 w-full" />
                    </div>
                    <div>
                        <label :class="label">{{ t('activities.partners') }}</label>
                        <TextInput v-model="form.partners" class="mt-1 w-full" />
                    </div>
                    <div>
                        <label :class="label">{{ t('activities.branch') }}</label>
                        <select v-model="form.branch_id" :class="field">
                            <option value="">—</option>
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.localized_name }}</option>
                        </select>
                    </div>
                    <div>
                        <label :class="label">{{ t('activities.responsible') }}</label>
                        <SearchableSelect v-model="form.responsible_board_member_id" :options="boardMembers" class="mt-1" />
                    </div>
                </div>
                <div>
                    <span :class="label">{{ t('activities.categories') }}</span>
                    <div class="mt-2 flex flex-wrap gap-3">
                        <label v-for="c in categories" :key="c.id" class="flex items-center gap-1.5 text-sm text-slate-700 dark:text-slate-200">
                            <input v-model="form.category_ids" type="checkbox" :value="c.id" class="rounded border-slate-300 dark:border-slate-700" /> {{ c.localized_name }}
                        </label>
                    </div>
                </div>
            </section>

            <section :class="card">
                <h2 class="font-semibold text-slate-900 dark:text-slate-100">{{ t('activities.participants') }}</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label :class="label">{{ t('activities.beneficiaries') }}</label>
                        <TextInput v-model="form.beneficiaries_count" type="number" min="0" class="mt-1 w-full" />
                        <InputError :message="form.errors.beneficiaries_count" class="mt-1" />
                    </div>
                    <div>
                        <label :class="label">{{ t('activities.external_volunteers') }}</label>
                        <TextInput v-model="form.external_volunteers_count" type="number" min="0" class="mt-1 w-full" />
                    </div>
                    <div>
                        <label :class="label">{{ t('activities.cost') }}</label>
                        <TextInput v-model="form.cost" type="number" min="0" step="0.01" class="mt-1 w-full" />
                        <p class="mt-1 text-xs text-slate-500">{{ t('activities.cost_hint') }}</p>
                    </div>
                </div>
                <div>
                    <label :class="label">{{ t('activities.players') }}</label>
                    <ParticipantPicker v-model="form.player_ids" :options="players" :placeholder="t('activities.pick_player')" class="mt-1" />
                </div>
                <div>
                    <label :class="label">{{ t('activities.board_members') }}</label>
                    <ParticipantPicker v-model="form.board_member_ids" :options="boardMembers" :placeholder="t('activities.pick_member')" class="mt-1" />
                </div>
            </section>

            <section :class="card">
                <div>
                    <label :class="label">{{ t('activities.description') }}</label>
                    <textarea v-model="form.description" rows="3" :class="field" />
                </div>
                <div>
                    <label :class="label">{{ t('activities.report') }}</label>
                    <textarea v-model="form.report" rows="8" :class="field" />
                    <p class="mt-1 text-xs text-slate-500">{{ t('activities.report_hint') }}</p>
                    <InputError :message="form.errors.report" class="mt-1" />
                </div>
            </section>

            <div class="flex justify-end gap-3">
                <Link :href="activity ? route('activities.show', activity.id) : route('activities.index')" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">{{ t('cancel') }}</Link>
                <PrimaryButton :disabled="form.processing">{{ t('save') }}</PrimaryButton>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
