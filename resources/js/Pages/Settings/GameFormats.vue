<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import IconButton from '@/Components/IconButton.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';

const { t } = useI18n();

const props = defineProps({
    formats: Array,
    branches: Array,
    sports: Array,
    periodLabels: Array,
    scorings: Array,
});

const editing = ref(null); // null | 'new' | format
const deleteId = ref(null);

const blank = () => ({
    branch_id: '', sport: 'football', name: '', name_ar: '', name_fr: '', name_en: '',
    players_on_field: 11, max_bench: 9, max_called_up: 20, periods_count: 2, period_minutes: 45,
    period_label: 'half', scoring: 'goals', sets_to_win: null,
    has_extra_time: false, has_shootout: false, active: true, sanction_types: [],
    max_substitutions: null, extra_time_minutes: 0, rolling_subs: false,
});
const form = useForm(blank());

const bySport = computed(() => props.sports
    .map((sport) => ({ sport, formats: props.formats.filter((f) => f.sport === sport) }))
    .filter((group) => group.formats.length));

// Unknown sanction keys (added by an admin) show as typed.
function sanctionLabel(key) {
    const label = t(`comp.sanction.${key}`);
    return label === `comp.sanction.${key}` ? key : label;
}

function openNew() {
    editing.value = 'new';
    form.defaults(blank());
    form.reset();
    form.clearErrors();
}

function openEdit(format) {
    editing.value = format;
    form.clearErrors();
    Object.assign(form, {
        ...blank(),
        ...Object.fromEntries(Object.keys(blank()).map((k) => [k, format[k] ?? blank()[k]])),
        branch_id: format.branch_id ?? '',
        sanction_types: (format.sanction_types ?? []).map((s) => ({ ...s })),
    });
}

function addSanction() {
    form.sanction_types.push({ key: '', ends_play: false, counts_as: null });
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => { editing.value = null; } };
    const send = form.transform((d) => ({ ...d, max_substitutions: d.max_substitutions || null }));
    if (editing.value === 'new') send.post(route('game-formats.store'), options);
    else send.put(route('game-formats.update', editing.value.id), options);
}

function destroy() {
    router.delete(route('game-formats.destroy', deleteId.value), { onFinish: () => { deleteId.value = null; } });
}
</script>

<template>
    <Head :title="t('game_formats')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('game_formats') }}</h1>
                <PrimaryButton @click="openNew">{{ t('comp.new_format') }}</PrimaryButton>
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-6">
            <form v-if="editing" @submit.prevent="save" class="space-y-4 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="grid gap-3 sm:grid-cols-4">
                    <label class="text-sm">{{ t('comp.sport') }}
                        <select v-model="form.sport" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="s in sports" :key="s" :value="s">{{ t(`comp.sport_${s}`) }}</option>
                        </select>
                        <InputError :message="form.errors.sport ? t(form.errors.sport) : ''" class="mt-1" />
                    </label>
                    <label class="text-sm">{{ t('branch') }}
                        <select v-model="form.branch_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="">—</option>
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.localized_name || b.name }}</option>
                        </select>
                        <InputError :message="form.errors.branch_id ? t(form.errors.branch_id) : ''" class="mt-1" />
                    </label>
                    <label class="text-sm sm:col-span-2">{{ t('name') }}
                        <TextInput v-model="form.name" class="mt-1 w-full" required />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </label>
                    <label class="text-sm">العربية <TextInput v-model="form.name_ar" dir="rtl" class="mt-1 w-full" /></label>
                    <label class="text-sm">Français <TextInput v-model="form.name_fr" class="mt-1 w-full" /></label>
                    <label class="text-sm">English <TextInput v-model="form.name_en" class="mt-1 w-full" /></label>
                </div>

                <div class="grid gap-3 sm:grid-cols-4">
                    <label class="text-sm">{{ t('comp.players_on_field') }}
                        <TextInput v-model.number="form.players_on_field" type="number" min="1" class="mt-1 w-full" />
                    </label>
                    <label class="text-sm">{{ t('comp.max_bench') }}
                        <TextInput v-model.number="form.max_bench" type="number" min="0" class="mt-1 w-full" />
                    </label>
                    <label class="text-sm">{{ t('comp.max_called_up') }}
                        <TextInput v-model.number="form.max_called_up" type="number" min="1" class="mt-1 w-full" />
                        <InputError :message="form.errors.max_called_up" class="mt-1" />
                    </label>
                    <label class="text-sm">{{ t('comp.max_substitutions') }}
                        <TextInput v-model.number="form.max_substitutions" type="number" min="1" class="mt-1 w-full" :placeholder="t('comp.no_limit')" />
                    </label>
                    <label class="text-sm">{{ t('comp.scoring') }}
                        <select v-model="form.scoring" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="s in scorings" :key="s" :value="s">{{ t(`comp.scoring_${s}`) }}</option>
                        </select>
                    </label>
                    <label class="text-sm">{{ t('comp.periods') }}
                        <TextInput v-model.number="form.periods_count" type="number" min="1" class="mt-1 w-full" />
                    </label>
                    <label class="text-sm">{{ t('comp.period_minutes') }}
                        <TextInput v-model.number="form.period_minutes" type="number" min="0" class="mt-1 w-full" />
                    </label>
                    <label class="text-sm">{{ t('comp.period_label') }}
                        <select v-model="form.period_label" class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="p in periodLabels" :key="p" :value="p">{{ t(`comp.period_${p}`) }}</option>
                        </select>
                    </label>
                    <label v-if="form.scoring === 'sets'" class="text-sm">{{ t('comp.sets_to_win') }}
                        <TextInput v-model.number="form.sets_to_win" type="number" min="1" class="mt-1 w-full" />
                        <InputError :message="form.errors.sets_to_win" class="mt-1" />
                    </label>
                </div>

                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="flex items-center gap-2"><input v-model="form.has_extra_time" type="checkbox" class="rounded" /> {{ t('comp.has_extra_time') }}</label>
                    <label v-if="form.has_extra_time" class="flex items-center gap-2">{{ t('comp.extra_time_minutes') }}
                        <TextInput v-model.number="form.extra_time_minutes" type="number" min="0" class="w-20" />
                    </label>
                    <label class="flex items-center gap-2"><input v-model="form.rolling_subs" type="checkbox" class="rounded" /> {{ t('comp.rolling_subs') }}</label>
                    <label class="flex items-center gap-2"><input v-model="form.has_shootout" type="checkbox" class="rounded" /> {{ t('comp.has_shootout') }}</label>
                    <label class="flex items-center gap-2"><input v-model="form.active" type="checkbox" class="rounded" /> {{ t('comp.active') }}</label>
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ t('comp.sanctions') }}</h3>
                        <SecondaryButton type="button" @click="addSanction">{{ t('add') }}</SecondaryButton>
                    </div>
                    <div v-for="(s, i) in form.sanction_types" :key="i" class="mt-2 flex flex-wrap items-center gap-3 text-sm">
                        <TextInput v-model="s.key" class="w-40 font-mono" placeholder="yellow" />
                        <span class="w-32 text-slate-500">{{ sanctionLabel(s.key) }}</span>
                        <label class="flex items-center gap-1"><input v-model="s.ends_play" type="checkbox" class="rounded" /> {{ t('comp.ends_play') }}</label>
                        <select v-model="s.counts_as" class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option :value="null">{{ t('comp.counts_as_none') }}</option>
                            <option value="yellow">{{ t('comp.counts_as_yellow') }}</option>
                            <option value="red">{{ t('comp.counts_as_red') }}</option>
                        </select>
                        <IconButton icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="form.sanction_types.splice(i, 1)" />
                        <InputError :message="form.errors[`sanction_types.${i}.key`]" class="w-full" />
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" @click="editing = null">{{ t('cancel') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">{{ t('save') }}</PrimaryButton>
                </div>
            </form>

            <section v-for="group in bySport" :key="group.sport" class="space-y-2">
                <h2 class="text-sm font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t(`comp.sport_${group.sport}`) }}</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div v-for="format in group.formats" :key="format.id" class="rounded-2xl bg-white dark:bg-slate-900 p-4 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800" :class="{ 'opacity-60': !format.active }">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <div class="font-semibold text-slate-900 dark:text-slate-100">{{ format.localized_name }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ format.branch ? (format.branch.localized_name || format.branch.name) : t('comp.no_branch') }}
                                    <span v-if="format.is_preset"> · {{ t('comp.preset') }}</span>
                                </div>
                            </div>
                            <div class="flex gap-1">
                                <IconButton icon="pencil" :label="t('edit')" plain size="sm" @click="openEdit(format)" />
                                <IconButton icon="trash" :label="t('delete')" variant="danger" plain size="sm" @click="deleteId = format.id" />
                            </div>
                        </div>
                        <div class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                            {{ t('comp.format_summary', { field: format.players_on_field, bench: format.max_bench, called: format.max_called_up }) }}
                            · {{ format.periods_count }} × {{ t(`comp.period_${format.period_label}`) }}<span v-if="format.period_minutes"> ({{ format.period_minutes }}′)</span>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <span v-for="f in format.formations" :key="f.id" class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs dark:bg-slate-800">{{ f.name }}</span>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
