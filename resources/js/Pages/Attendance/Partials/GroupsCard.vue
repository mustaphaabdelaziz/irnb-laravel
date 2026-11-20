<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import IconButton from '@/Components/IconButton.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { categoriesForBranch } from '@/lib/categoryPicker';
import { groupSections, memberChanges } from '@/lib/groupMembers';
import { localToday } from '@/lib/localDate';

const props = defineProps({
    branches: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    groups: { type: Array, default: () => [] }, // [{ id, branch_id, category_id, name, key, members, in_use }]
    rosters: { type: [Object, Array], default: () => ({}) }, // key => [{ id, name, group_id }]; [] when there is no group
});
const { t } = useI18n();
const today = localToday();

const input = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900';
const card = 'rounded-xl bg-white p-4 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800';
// Only att.* values are translation keys; Laravel's own messages show as they are.
const tr = (e) => (typeof e === 'string' && e.startsWith('att.') ? t(e) : e);
const branchName = (id) => props.branches.find((b) => b.id === id)?.name ?? '';
const categoryName = (id) => props.categories.find((c) => c.id === id)?.name ?? '';
const groupName = (id) => props.groups.find((g) => g.id === id)?.name ?? '';

// ---- Create or rename ----
const editingId = ref(null);
const firstOf = (branchId) => categoriesForBranch(props.categories, branchId)[0]?.id ?? null;
const form = useForm({ branch_id: props.branches[0]?.id ?? null, category_id: firstOf(props.branches[0]?.id ?? null), name: '' });
const formCategories = computed(() => categoriesForBranch(props.categories, form.branch_id, [form.category_id]));
watch(() => form.branch_id, (branchId) => {
    if (!categoriesForBranch(props.categories, branchId).some((c) => c.id === form.category_id)) form.category_id = firstOf(branchId);
});
function edit(group) {
    editingId.value = group.id;
    form.clearErrors();
    Object.assign(form, { branch_id: group.branch_id, category_id: group.category_id, name: group.name });
    // The branch watch runs after both are set; keep a stored category selectable.
    nextTick(() => { form.category_id = group.category_id; });
}
function resetForm() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
}
function submit() {
    if (editingId.value) {
        form.put(route('attendance.groups.update', editingId.value), { preserveScroll: true, onSuccess: resetForm });
    } else {
        // Branch and category stay chosen: the next group of the same category is one name away.
        form.post(route('attendance.groups.store'), { preserveScroll: true, onSuccess: () => form.reset('name') });
    }
}
function destroy(group) {
    if (window.confirm(t('att.confirm_delete'))) router.delete(route('attendance.groups.destroy', group.id), { preserveScroll: true });
}

// ---- List: one block per branch + category, with who is waiting for a group ----
const sections = computed(() => groupSections(props.groups, props.rosters));
const waitingOpen = ref(null); // the section key whose waiting names are shown

// ---- Players of one group: a tick list, saved as changes from a date ----
const open = ref(null); // the group being edited
const ticked = ref([]);
const from = ref('');
const query = ref('');
const saving = ref(false);
const memberError = ref('');
const roster = computed(() => (open.value ? props.rosters[open.value.key] ?? [] : []));
const shown = computed(() => {
    const q = query.value.trim().toLowerCase();
    return q ? roster.value.filter((p) => p.name.toLowerCase().includes(q)) : roster.value;
});
const membersBefore = () => roster.value.filter((p) => p.group_id === open.value.id).map((p) => p.id);
function openPlayers(group) {
    open.value = group;
    query.value = '';
    memberError.value = '';
    ticked.value = (props.rosters[group.key] ?? []).filter((p) => p.group_id === group.id).map((p) => p.id);
    // An empty group is a first setup: from the beginning. Later changes default to today.
    from.value = group.members ? today : '';
}
function savePlayers() {
    const { add, remove } = memberChanges(membersBefore(), ticked.value);
    if (!add.length && !remove.length) {
        open.value = null;
        return;
    }
    router.put(route('attendance.groups.members.update', open.value.id), { from: from.value || null, add, remove }, {
        preserveScroll: true,
        onStart: () => { saving.value = true; memberError.value = ''; },
        onFinish: () => { saving.value = false; },
        onSuccess: () => { open.value = null; },
        onError: (errors) => { memberError.value = tr(Object.values(errors)[0]); },
    });
}
</script>

<template>
    <section data-help="set-groups" :class="card">
        <h2 class="mb-1 font-bold text-slate-900 dark:text-slate-100">{{ t('att.groups') }}</h2>
        <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">{{ t('att.groups_help') }}</p>

        <form data-help="set-group-form" class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="submit">
            <label v-if="branches.length" class="text-xs text-slate-500 dark:text-slate-400">{{ t('branch') }}
                <select v-model="form.branch_id" :disabled="!!editingId" :class="[input, 'block']"><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
            </label>
            <label class="text-xs text-slate-500 dark:text-slate-400">{{ t('att.category') }}
                <select v-model="form.category_id" :disabled="!!editingId" :class="[input, 'block']"><option v-for="c in formCategories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
            </label>
            <label class="text-xs text-slate-500 dark:text-slate-400">{{ t('att.group_name') }}
                <input v-model="form.name" type="text" maxlength="60" dir="auto" :class="[input, 'block w-44']" />
            </label>
            <button data-help="set-group-add" type="submit" :disabled="form.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ editingId ? t('att.save') : t('att.add') }}</button>
            <button v-if="editingId" type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:text-slate-400 dark:ring-slate-700" @click="resetForm">{{ t('att.close') }}</button>
            <div class="w-full"><InputError v-for="(e, k) in form.errors" :key="k" :message="tr(e)" /></div>
        </form>

        <p v-if="!groups.length" class="text-sm text-slate-500 dark:text-slate-400">{{ t('att.group_none') }}</p>

        <div v-for="s in sections" :key="s.key" class="border-t border-slate-100 py-2 dark:border-slate-800">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ [branchName(s.branch_id), categoryName(s.category_id)].filter(Boolean).join(' · ') }}</h3>
                <button
                    v-if="s.waiting.length"
                    type="button"
                    class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800 hover:bg-amber-200 dark:bg-amber-500/20 dark:text-amber-300 dark:hover:bg-amber-500/30"
                    :aria-expanded="waitingOpen === s.key"
                    @click="waitingOpen = waitingOpen === s.key ? null : s.key"
                >{{ t('att.group_waiting', { n: s.waiting.length }) }}</button>
            </div>
            <ul v-if="waitingOpen === s.key" class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-amber-800 dark:text-amber-300">
                <li v-for="p in s.waiting" :key="p.id">{{ p.name }}</li>
            </ul>
            <table class="mt-1 w-full text-sm">
                <tbody>
                    <tr v-for="g in s.groups" :key="g.id">
                        <td class="py-1.5 font-medium">{{ g.name }}</td>
                        <td class="text-slate-500 dark:text-slate-400">{{ t('att.group_count', { n: g.members }) }}</td>
                        <td class="whitespace-nowrap text-end">
                            <IconButton data-help="set-group-players" icon="players" :label="t('att.group_players')" plain size="sm" @click="openPlayers(g)" />
                            <IconButton icon="pencil" :label="t('att.edit')" plain size="sm" @click="edit(g)" />
                            <IconButton icon="trash" :label="t('att.delete')" variant="danger" plain size="sm" :disabled="g.in_use" :title="g.in_use ? t('flash.training_group_in_use') : t('att.delete')" @click="destroy(g)" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :show="!!open" max-width="md" @close="open = null">
            <form v-if="open" class="space-y-3 p-5" @submit.prevent="savePlayers">
                <h2 class="font-bold text-slate-900 dark:text-slate-100">{{ t('att.group_players') }} · {{ open.name }}</h2>
                <label class="block text-sm">{{ t('att.group_from') }}
                    <input v-model="from" type="date" :class="[input, 'mt-1 block']" />
                    <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ t('att.group_from_help') }}</span>
                </label>
                <input v-model="query" type="text" :placeholder="t('att.group_find')" :aria-label="t('att.group_find')" :class="[input, 'block w-full']" />
                <p v-if="!roster.length" class="text-sm text-slate-500 dark:text-slate-400">{{ t('att.group_no_players') }}</p>
                <ul class="max-h-80 space-y-1 overflow-y-auto text-sm">
                    <li v-for="p in shown" :key="p.id">
                        <label class="flex items-center gap-2">
                            <input v-model="ticked" type="checkbox" :value="p.id" class="rounded border-slate-300 text-primary-600 dark:border-slate-700 dark:bg-slate-900" />
                            <span class="min-w-0 flex-1 truncate">{{ p.name }}</span>
                            <span v-if="p.group_id && p.group_id !== open.id" class="shrink-0 rounded bg-slate-100 px-1.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('att.group_in_other', { group: groupName(p.group_id) }) }}</span>
                        </label>
                    </li>
                </ul>
                <InputError :message="memberError" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200 dark:text-slate-400 dark:ring-slate-700" @click="open = null">{{ t('att.close') }}</button>
                    <button type="submit" :disabled="saving" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">{{ t('att.save') }}</button>
                </div>
            </form>
        </Modal>
    </section>
</template>
