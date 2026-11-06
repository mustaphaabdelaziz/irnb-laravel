<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import IconButton from '@/Components/IconButton.vue';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { localToday } from '@/lib/localDate';
import { categoriesForBranch } from '@/lib/categoryPicker';
import { useCan } from '@/Composables/useCan';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({
    card: { type: Object, required: true },
    index: { type: Number, required: true },
    branch: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    positions: { type: Array, default: () => [] },
    playerStatuses: { type: Array, default: () => [] },
    sportingStatuses: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
});
defineEmits(['remove', 'delete']);

const fieldError = (field) => props.errors[`registrations.${props.index}.${field}`];
// The list's own error and any item's error (registrations.N.other_position_ids.M).
const otherPositionsError = computed(() => {
    const prefix = `registrations.${props.index}.other_position_ids`;
    return Object.keys(props.errors)
        .filter((key) => key === prefix || key.startsWith(`${prefix}.`))
        .map((key) => props.errors[key])[0];
});
const selectClass = 'mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500';

// A stored position from another branch stays selectable until it is changed.
// Only this branch's categories, plus the stored one until it is changed.
const branchCategories = computed(() => categoriesForBranch(props.categories, props.branch.id, [props.card.category_id]));

const branchPositions = computed(() => props.positions.filter((position) => Number(position.branch_id) === Number(props.branch.id)
    || position.branch_id === null
    || Number(position.id) === Number(props.card.position_id)));
const hasPositions = computed(() => branchPositions.value.length > 0);
const otherOptions = computed(() => branchPositions.value
    .filter((position) => Number(position.id) !== Number(props.card.position_id)
        && !props.card.other_position_ids.some((id) => Number(id) === Number(position.id)))
    .map((position) => ({ value: position.id, label: `${position.abbreviation} - ${position.name}` })));
const chosenOthers = computed(() => props.card.other_position_ids
    .map((id) => props.positions.find((position) => Number(position.id) === Number(id)))
    .filter(Boolean));

function addOther(id) {
    if (id === '' || id === null || id === undefined) return;
    if (!props.card.other_position_ids.some((value) => Number(value) === Number(id))) props.card.other_position_ids.push(Number(id));
}
function removeOther(id) {
    props.card.other_position_ids = props.card.other_position_ids.filter((value) => Number(value) !== Number(id));
}
watch(() => props.card.position_id, (id) => { removeOther(id); });

// The built-in "left" status is found by its code, never its editable name.
const isLeft = computed(() => props.playerStatuses.find((s) => Number(s.id) === Number(props.card.status_id))?.code === 'left');
// Stamp today only when the status is changed to "left" in this form: a saved
// left registration may have no recorded day, and opening it must not invent one.
watch(isLeft, (left) => {
    if (left && !props.card.left_at) props.card.left_at = localToday();
});

// Empty date on a saved row: the correction queue's case, so it may stay empty here.
const dateRequired = computed(() => props.card.is_new || props.card.had_date);
</script>

<template>
    <section class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
        <header class="mb-3 flex items-center justify-between gap-2">
            <div class="flex min-w-0 items-center gap-2">
                <img v-if="branch.logo_url" :src="branch.logo_url" alt="" class="h-6 w-6 rounded-sm object-contain" />
                <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ branch.localized_name || branch.name }}</h3>
                <span v-if="card.membership_id" class="font-mono text-xs text-slate-500 dark:text-slate-400" dir="ltr">{{ card.membership_id }}</span>
            </div>
            <IconButton v-if="card.is_new" icon="trash" :label="t('remove')" variant="danger" plain size="sm" @click="$emit('remove')" />
            <IconButton v-else-if="can('players', 'delete')" icon="trash" :label="t('registration_delete')" variant="danger" plain size="sm" @click="$emit('delete')" />
        </header>

        <InputError :message="fieldError('branch_id')" class="mb-3" />

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <InputLabel :value="t('join_date') + (dateRequired ? ' *' : '')" />
                <TextInput v-model="card.join_date" type="date" class="mt-1 w-full" :required="dateRequired" />
                <p v-if="!card.join_date && card.legacy_join_year" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                    {{ t('join_year_only', { year: card.legacy_join_year }) }}
                </p>
                <InputError :message="fieldError('join_date')" class="mt-1" />
            </div>
            <div v-if="branchCategories.length">
                <InputLabel :value="t('category')" />
                <select v-model="card.category_id" :class="selectClass">
                    <option value="">{{ t('select_category') }}</option>
                    <option v-for="category in branchCategories" :key="category.id" :value="category.id">{{ category.localized_name || category.name }}</option>
                </select>
                <InputError :message="fieldError('category_id')" class="mt-1" />
            </div>
            <div v-if="hasPositions">
                <InputLabel :value="t('main_position')" />
                <select v-model="card.position_id" :class="selectClass">
                    <option value="">-</option>
                    <option v-for="position in branchPositions" :key="position.id" :value="position.id">{{ position.abbreviation }} - {{ position.name }}</option>
                </select>
                <InputError :message="fieldError('position_id')" class="mt-1" />
            </div>
            <div v-if="hasPositions">
                <InputLabel :value="t('other_positions')" />
                <div v-if="chosenOthers.length" class="mt-1 flex flex-wrap gap-1.5">
                    <span v-for="position in chosenOthers" :key="position.id" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        {{ position.abbreviation }}
                        <button type="button" :aria-label="t('remove')" class="text-slate-400 hover:text-rose-500 dark:hover:text-rose-400" @click="removeOther(position.id)">&times;</button>
                    </span>
                </div>
                <SearchableSelect v-if="otherOptions.length" :model-value="''" :options="otherOptions" :placeholder="t('add_position')" class="mt-1" @update:modelValue="addOther" />
                <InputError :message="otherPositionsError" class="mt-1" />
            </div>
            <div>
                <InputLabel :value="t('licence_number')" />
                <TextInput v-model="card.licence_number" class="mt-1 w-full" maxlength="50" dir="ltr" />
                <InputError :message="fieldError('licence_number')" class="mt-1" />
            </div>
            <div>
                <InputLabel :value="t('membership_status')" />
                <select v-model="card.status_id" :class="selectClass">
                    <option value="">-</option>
                    <option v-for="status in playerStatuses" :key="status.id" :value="status.id">{{ status.localized_name }}</option>
                </select>
                <InputError :message="fieldError('status_id')" class="mt-1" />
            </div>
            <div v-if="isLeft">
                <InputLabel :value="t('left_at')" />
                <TextInput v-model="card.left_at" type="date" class="mt-1 w-full" />
                <InputError :message="fieldError('left_at')" class="mt-1" />
            </div>
            <div v-if="sportingStatuses.length">
                <InputLabel :value="t('sporting_status')" />
                <select v-model="card.sporting_status_id" :class="selectClass">
                    <option value="">-</option>
                    <option v-for="status in sportingStatuses" :key="status.id" :value="status.id">{{ status.localized_name }}</option>
                </select>
                <InputError :message="fieldError('sporting_status_id')" class="mt-1" />
            </div>
        </div>
    </section>
</template>
