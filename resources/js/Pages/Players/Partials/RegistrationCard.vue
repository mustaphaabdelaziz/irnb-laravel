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
import { groupsFor } from '@/lib/groupPicker';
import { groupAfterCategoryChange, statusChanged } from '@/lib/registrationCards';
import { useCan } from '@/Composables/useCan';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({
    card: { type: Object, required: true },
    index: { type: Number, required: true },
    branch: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    groups: { type: Array, default: () => [] }, // every training group: [{ id, branch_id, category_id, name }]
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

// Only this branch's categories, plus the stored one until it is changed.
const branchCategories = computed(() => categoriesForBranch(props.categories, props.branch.id, [props.card.category_id]));

// The training groups of this card's branch + category; none = the field is not shown.
const groupOptions = computed(() => groupsFor(props.groups, props.branch.id, props.card.category_id));
// A group belongs to one category: another category drops the choice; back in the saved
// category the saved group returns (a round trip changes nothing and sends nothing).
watch(() => props.card.category_id, () => {
    props.card.group_id = groupAfterCategoryChange(props.card, groupOptions.value);
});
// The "from" day shows only when a saved group changes: a first assignment has no start date.
const groupMoved = computed(() => !!props.card.original_group_id
    && Number(props.card.group_id || 0) !== Number(props.card.original_group_id));
// Today by default; it can be emptied = from the beginning.
watch(groupMoved, (moved) => { props.card.group_from = moved ? localToday() : ''; });
// Leaving the saved group because the category changed needs the day: "from the beginning"
// would erase the old group's history, so the server refuses an empty one.
const leftByCategory = computed(() => {
    const saved = props.groups.find((group) => Number(group.id) === Number(props.card.original_group_id));
    return !!saved && Number(saved.category_id) !== Number(props.card.category_id || 0);
});
watch(leftByCategory, (left) => {
    if (left && !props.card.group_from) props.card.group_from = localToday();
});

// A stored position from another branch stays selectable until it is changed.
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

// A saved card whose status is changed asks when it happened, and why (optional).
const changing = computed(() => statusChanged(props.card));
watch(changing, (on) => {
    if (on && !props.card.status_changed_on) props.card.status_changed_on = localToday();
}, { immediate: true });
const today = localToday();

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
            <div v-if="groupOptions.length" data-help="player-group">
                <InputLabel :value="t('att.group')" />
                <select v-model="card.group_id" :class="selectClass">
                    <option value="">-</option>
                    <option v-for="group in groupOptions" :key="group.id" :value="group.id">{{ group.name }}</option>
                </select>
                <InputError :message="fieldError('group_id')" class="mt-1" />
            </div>
            <div v-if="groupOptions.length && groupMoved" data-help="player-group-from">
                <InputLabel :value="t('att.group_from') + (leftByCategory ? ' *' : '')" />
                <TextInput v-model="card.group_from" type="date" class="mt-1 w-full" :required="leftByCategory" />
                <p v-if="!leftByCategory" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('att.group_from_help') }}</p>
                <InputError :message="fieldError('group_from')" class="mt-1" />
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
            <template v-if="changing">
                <div>
                    <InputLabel :value="t('status_change.date') + ' *'" />
                    <TextInput v-model="card.status_changed_on" type="date" class="mt-1 w-full" :max="today" required />
                    <InputError :message="fieldError('status_changed_on')" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('status_change.note')" />
                    <TextInput v-model="card.status_note" class="mt-1 w-full" maxlength="255" :placeholder="t('status_change.note_hint')" />
                    <InputError :message="fieldError('status_note')" class="mt-1" />
                </div>
            </template>
            <div v-else-if="isLeft">
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
